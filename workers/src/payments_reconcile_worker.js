const fs = require("fs");
const path = require("path");
const crypto = require("crypto");
const { createPool } = require("./db");

const JOB_TYPE = "payments_reconcile_import";
const ONCE = process.argv.includes("--once");
const LOOP_SECONDS = Number(process.env.WORKER_PAYMENTS_INTERVAL_SECONDS || 300);
const DRY_RUN = String(process.env.WORKER_PAYMENTS_DRY_RUN || "true").toLowerCase() !== "false";
const IMPORT_DIR = process.env.WORKER_PAYMENTS_IMPORT_DIR || path.resolve(__dirname, "../imports/payments");
const ARCHIVE_DIR = process.env.WORKER_PAYMENTS_ARCHIVE_DIR || path.resolve(__dirname, "../imports/archive");

const ALLOWED_METHODS = new Set([
  "airtel_money",
  "tnm_mpamba",
  "bank_transfer",
  "cash",
  "sticpay",
  "mastercard",
  "visa",
  "binance",
  "other"
]);

function nowIso() {
  return new Date().toISOString();
}

function toDateYmd(date) {
  return date.toISOString().slice(0, 10);
}

function ensureDirs() {
  for (const p of [IMPORT_DIR, ARCHIVE_DIR]) {
    if (!fs.existsSync(p)) {
      fs.mkdirSync(p, { recursive: true });
    }
  }
}

function parseCsvLine(line) {
  const out = [];
  let cur = "";
  let inQuotes = false;
  for (let i = 0; i < line.length; i += 1) {
    const ch = line[i];
    if (ch === '"') {
      if (inQuotes && line[i + 1] === '"') {
        cur += '"';
        i += 1;
      } else {
        inQuotes = !inQuotes;
      }
      continue;
    }
    if (ch === "," && !inQuotes) {
      out.push(cur);
      cur = "";
      continue;
    }
    cur += ch;
  }
  out.push(cur);
  return out.map((x) => x.trim());
}

function parseCsvFile(filePath) {
  const raw = fs.readFileSync(filePath, "utf8");
  const lines = raw.split(/\r?\n/).filter((l) => l.trim() !== "");
  if (lines.length < 2) return [];

  const headers = parseCsvLine(lines[0]).map((h) => h.toLowerCase());
  const records = [];
  for (let i = 1; i < lines.length; i += 1) {
    const cols = parseCsvLine(lines[i]);
    const record = {};
    for (let c = 0; c < headers.length; c += 1) {
      record[headers[c]] = cols[c] ?? "";
    }
    records.push(record);
  }
  return records;
}

function normalizePaymentMethod(method) {
  const value = String(method || "").trim().toLowerCase();
  if (ALLOWED_METHODS.has(value)) return value;
  return "other";
}

function normalizeDateYmd(value, fallbackDate) {
  const v = String(value || "").trim();
  if (/^\d{4}-\d{2}-\d{2}$/.test(v)) return v;
  return fallbackDate;
}

function normalizeDateTime(value) {
  const v = String(value || "").trim();
  if (v === "") return null;
  if (/^\d{4}-\d{2}-\d{2}( \d{2}:\d{2}:\d{2})?$/.test(v)) {
    return v.length === 10 ? `${v} 00:00:00` : v;
  }
  return null;
}

async function reserveJob(conn, eventKey, runDate, payload) {
  const [res] = await conn.execute(
    `INSERT IGNORE INTO worker_job_runs
      (job_type, event_key, run_date, status, attempts, payload_json, created_at, updated_at)
     VALUES (?, ?, ?, 'running', 1, ?, NOW(), NOW())`,
    [JOB_TYPE, eventKey, runDate, JSON.stringify(payload)]
  );
  return res.affectedRows > 0;
}

async function completeJob(conn, eventKey, runDate) {
  await conn.execute(
    `UPDATE worker_job_runs
     SET status = 'completed', updated_at = NOW()
     WHERE job_type = ? AND event_key = ? AND run_date = ?`,
    [JOB_TYPE, eventKey, runDate]
  );
}

async function failJob(conn, eventKey, runDate, errorText) {
  await conn.execute(
    `UPDATE worker_job_runs
     SET status = 'failed', last_error = ?, updated_at = NOW()
     WHERE job_type = ? AND event_key = ? AND run_date = ?`,
    [String(errorText).slice(0, 1000), JOB_TYPE, eventKey, runDate]
  );
}

async function createAudit(conn, action, entityId, meta) {
  await conn.execute(
    `INSERT INTO audit_log
      (user_id, action, entity_type, entity_id, old_values, new_values, ip_address, user_agent, created_at)
     VALUES (?, ?, 'worker_job_runs', ?, NULL, ?, '127.0.0.1', 'node-worker/payments-reconcile', NOW())`,
    [meta.user_id || null, action, entityId || null, JSON.stringify(meta)]
  );
}

async function paymentAlreadyExists(conn, paymentReference) {
  if (!paymentReference) return false;
  const [rows] = await conn.execute(
    `SELECT repayment_id FROM repayments WHERE payment_reference = ? LIMIT 1`,
    [paymentReference]
  );
  return rows.length > 0;
}

async function fetchLoanAndUser(conn, loanId, userId) {
  const [rows] = await conn.execute(
    `SELECT loan_id, user_id, status
     FROM loans
     WHERE loan_id = ? AND user_id = ?
     LIMIT 1`,
    [loanId, userId]
  );
  return rows[0] || null;
}

async function insertRepayment(conn, item) {
  const [res] = await conn.execute(
    `INSERT INTO repayments
      (loan_id, user_id, amount_paid_mwk, payment_method, payment_reference, due_date,
       paid_at, payment_status, status, is_partial, notes)
     VALUES (?, ?, ?, ?, ?, ?, ?, 'completed', 'on_time', ?, ?)`,
    [
      item.loan_id,
      item.user_id,
      item.amount_paid_mwk,
      item.payment_method,
      item.payment_reference || null,
      item.due_date,
      item.paid_at || null,
      item.is_partial ? 1 : 0,
      item.notes || null
    ]
  );
  return res.insertId;
}

function mapRecord(record) {
  const today = toDateYmd(new Date());
  const loanId = Number(record.loan_id || 0);
  const userId = Number(record.user_id || 0);
  const amountPaid = Number(record.amount_paid_mwk || record.amount || 0);
  const method = normalizePaymentMethod(record.payment_method || record.method);
  const paymentRef = String(record.payment_reference || record.external_reference || "").trim();
  const dueDate = normalizeDateYmd(record.due_date, today);
  const paidAt = normalizeDateTime(record.paid_at);
  const notes = String(record.notes || "Imported by payments reconcile worker").trim();
  const isPartial = String(record.is_partial || "").trim() === "1";

  if (loanId <= 0 || userId <= 0 || !(amountPaid > 0)) {
    return null;
  }

  return {
    loan_id: loanId,
    user_id: userId,
    amount_paid_mwk: amountPaid,
    payment_method: method,
    payment_reference: paymentRef,
    due_date: dueDate,
    paid_at: paidAt,
    is_partial: isPartial,
    notes
  };
}

function eventKeyForRecord(fileName, rowIndex, item) {
  if (item.payment_reference) {
    return `payment-ref:${item.payment_reference}`;
  }
  const hash = crypto
    .createHash("sha1")
    .update(`${fileName}:${rowIndex}:${item.loan_id}:${item.user_id}:${item.amount_paid_mwk}:${item.due_date}`)
    .digest("hex")
    .slice(0, 20);
  return `payment-row:${hash}`;
}

async function processFile(conn, filePath) {
  const records = parseCsvFile(filePath);
  const fileName = path.basename(filePath);
  const runDate = toDateYmd(new Date());
  let processed = 0;
  let skipped = 0;
  let failed = 0;

  for (let i = 0; i < records.length; i += 1) {
    const mapped = mapRecord(records[i]);
    if (!mapped) {
      skipped += 1;
      continue;
    }

    const eventKey = eventKeyForRecord(fileName, i + 1, mapped);
    const payload = { file: fileName, row_index: i + 1, ...mapped };
    const reserved = await reserveJob(conn, eventKey, runDate, payload);
    if (!reserved) {
      skipped += 1;
      continue;
    }

    try {
      const loan = await fetchLoanAndUser(conn, mapped.loan_id, mapped.user_id);
      if (!loan) {
        throw new Error(`Loan/user mismatch for loan_id=${mapped.loan_id}, user_id=${mapped.user_id}`);
      }
      if (!["active", "overdue"].includes(String(loan.status || ""))) {
        throw new Error(`Loan ${mapped.loan_id} is not payable (status=${loan.status})`);
      }

      if (await paymentAlreadyExists(conn, mapped.payment_reference)) {
        await createAudit(conn, "WORKER_PAYMENT_IMPORT_SKIPPED_DUP", null, {
          dry_run: DRY_RUN,
          file: fileName,
          row_index: i + 1,
          payment_reference: mapped.payment_reference,
          user_id: mapped.user_id
        });
        await completeJob(conn, eventKey, runDate);
        skipped += 1;
        continue;
      }

      let repaymentId = null;
      if (!DRY_RUN) {
        repaymentId = await insertRepayment(conn, mapped);
      }

      await createAudit(conn, "WORKER_PAYMENT_IMPORTED", repaymentId, {
        dry_run: DRY_RUN,
        file: fileName,
        row_index: i + 1,
        repayment_id: repaymentId,
        payment_reference: mapped.payment_reference,
        user_id: mapped.user_id,
        loan_id: mapped.loan_id
      });
      await completeJob(conn, eventKey, runDate);
      processed += 1;
    } catch (err) {
      await failJob(conn, eventKey, runDate, err.message || "Unknown worker error");
      failed += 1;
    }
  }

  return { processed, skipped, failed, total: records.length };
}

function listImportFiles() {
  ensureDirs();
  return fs
    .readdirSync(IMPORT_DIR)
    .filter((name) => name.toLowerCase().endsWith(".csv"))
    .map((name) => path.join(IMPORT_DIR, name));
}

function archiveFile(filePath) {
  const fileName = path.basename(filePath);
  const stamped = `${Date.now()}_${fileName}`;
  fs.renameSync(filePath, path.join(ARCHIVE_DIR, stamped));
}

async function processBatch(pool) {
  const conn = await pool.getConnection();
  let filesProcessed = 0;
  let totalProcessed = 0;
  let totalSkipped = 0;
  let totalFailed = 0;

  try {
    const files = listImportFiles();
    for (const filePath of files) {
      const stats = await processFile(conn, filePath);
      filesProcessed += 1;
      totalProcessed += stats.processed;
      totalSkipped += stats.skipped;
      totalFailed += stats.failed;

      if (!DRY_RUN && stats.failed === 0) {
        archiveFile(filePath);
      }
    }
  } finally {
    conn.release();
  }

  return {
    filesProcessed,
    processed: totalProcessed,
    skipped: totalSkipped,
    failed: totalFailed
  };
}

async function main() {
  const pool = createPool();
  console.log(`[${nowIso()}] ${JOB_TYPE} started (dry_run=${DRY_RUN}, once=${ONCE})`);
  console.log(`[${nowIso()}] import_dir=${IMPORT_DIR}`);

  try {
    if (ONCE) {
      const stats = await processBatch(pool);
      console.log(`[${nowIso()}] batch complete`, stats);
      return;
    }

    while (true) {
      const stats = await processBatch(pool);
      console.log(`[${nowIso()}] batch complete`, stats);
      await new Promise((resolve) => setTimeout(resolve, LOOP_SECONDS * 1000));
    }
  } catch (err) {
    console.error(`[${nowIso()}] worker failed`, err);
    process.exitCode = 1;
  } finally {
    await pool.end();
  }
}

main();

