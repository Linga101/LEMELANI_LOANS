const { createPool } = require("./db");

const JOB_TYPE = "webhooks_process";
const ONCE = process.argv.includes("--once");
const LOOP_SECONDS = Number(process.env.WORKER_WEBHOOKS_INTERVAL_SECONDS || 120);
const DRY_RUN = String(process.env.WORKER_WEBHOOKS_DRY_RUN || "true").toLowerCase() !== "false";
const BATCH_LIMIT = Number(process.env.WORKER_WEBHOOKS_BATCH_LIMIT || 100);

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

function intOrNull(v) {
  const n = Number(v);
  if (!Number.isFinite(n) || n <= 0) return null;
  return Math.trunc(n);
}

function amountOrNull(v) {
  const n = Number(v);
  if (!Number.isFinite(n) || n < 0) return null;
  return n;
}

function paymentMethodFromProvider(provider) {
  if (provider === "airtel_money") return "airtel_money";
  if (provider === "tnm_mpamba") return "tnm_mpamba";
  if (provider === "card_gateway") return "visa";
  return "other";
}

function normalizePaymentMethod(provider, payload) {
  const direct = String(payload.paymentMethod || payload.payment_method || "").trim().toLowerCase();
  if (ALLOWED_METHODS.has(direct)) return direct;
  return paymentMethodFromProvider(provider);
}

function normalizeDateYmd(value) {
  const v = String(value || "").trim();
  if (/^\d{4}-\d{2}-\d{2}$/.test(v)) return v;
  return toDateYmd(new Date());
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
     VALUES (?, ?, 'payment_webhook_events', ?, NULL, ?, '127.0.0.1', 'node-worker/webhooks-process', NOW())`,
    [meta.user_id || null, action, entityId || null, JSON.stringify(meta)]
  );
}

async function fetchPendingEvents(conn) {
  const [rows] = await conn.execute(
    `SELECT id, provider, event_id, event_type, processing_status, payload_json,
            normalized_reference, normalized_user_id, normalized_loan_id, normalized_amount_mwk, received_at
     FROM payment_webhook_events
     WHERE processing_status = 'received'
     ORDER BY received_at ASC, id ASC
     LIMIT ?`,
    [BATCH_LIMIT]
  );
  return rows;
}

async function loadLoan(conn, loanId) {
  const [rows] = await conn.execute(
    `SELECT loan_id, user_id, status
     FROM loans
     WHERE loan_id = ?
     LIMIT 1`,
    [loanId]
  );
  return rows[0] || null;
}

async function paymentRefExists(conn, reference) {
  if (!reference) return false;
  const [rows] = await conn.execute(
    `SELECT repayment_id FROM repayments WHERE payment_reference = ? LIMIT 1`,
    [reference]
  );
  return rows.length > 0;
}

async function markEvent(conn, id, status, errorMessage = null) {
  await conn.execute(
    `UPDATE payment_webhook_events
     SET processing_status = ?, error_message = ?, processed_at = NOW()
     WHERE id = ?`,
    [status, errorMessage, id]
  );
}

async function insertRepayment(conn, record) {
  const [res] = await conn.execute(
    `INSERT INTO repayments
      (loan_id, user_id, amount_paid_mwk, payment_method, payment_reference, due_date, paid_at,
       payment_status, status, is_partial, notes)
     VALUES (?, ?, ?, ?, ?, ?, ?, 'completed', 'on_time', ?, ?)`,
    [
      record.loan_id,
      record.user_id,
      record.amount_paid_mwk,
      record.payment_method,
      record.payment_reference || null,
      record.due_date,
      record.paid_at || null,
      record.is_partial ? 1 : 0,
      record.notes
    ]
  );
  return res.insertId;
}

function parsePayload(rawPayload) {
  if (!rawPayload) return {};
  if (typeof rawPayload === "object") return rawPayload;
  try {
    return JSON.parse(rawPayload);
  } catch {
    return {};
  }
}

function mapEventToPayment(event) {
  const payload = parsePayload(event.payload_json);
  const userId =
    intOrNull(event.normalized_user_id) ??
    intOrNull(payload.userId) ??
    intOrNull(payload.user_id) ??
    null;
  const loanId =
    intOrNull(event.normalized_loan_id) ??
    intOrNull(payload.loanId) ??
    intOrNull(payload.loan_id) ??
    null;
  const amountMwk =
    amountOrNull(event.normalized_amount_mwk) ??
    amountOrNull(payload.amount) ??
    amountOrNull(payload.amountPaidMwk) ??
    null;
  const paymentReference = String(
    event.normalized_reference ||
      payload.paymentReference ||
      payload.reference ||
      payload.transactionId ||
      payload.transaction_id ||
      payload.id ||
      event.event_id
  ).trim();

  const dueDate = normalizeDateYmd(payload.dueDate || payload.due_date);
  const paidAt = normalizeDateTime(payload.paidAt || payload.paid_at);
  const paymentMethod = normalizePaymentMethod(event.provider, payload);
  const isPartial = String(payload.isPartial || payload.is_partial || "").trim() === "1";
  const notes = `Webhook processed (${event.provider}/${event.event_id})`;

  if (!userId || !loanId || !(amountMwk > 0)) {
    return null;
  }

  return {
    user_id: userId,
    loan_id: loanId,
    amount_paid_mwk: amountMwk,
    payment_reference: paymentReference,
    payment_method: paymentMethod,
    due_date: dueDate,
    paid_at: paidAt,
    is_partial: isPartial,
    notes
  };
}

async function processEvent(conn, event, runDate) {
  const eventKey = `webhook-event:${event.id}`;
  const payload = {
    webhook_event_id: event.id,
    provider: event.provider,
    event_id: event.event_id
  };
  const reserved = await reserveJob(conn, eventKey, runDate, payload);
  if (!reserved) {
    return { processed: 0, ignored: 1, failed: 0, skipped: 1 };
  }

  try {
    const payment = mapEventToPayment(event);
    if (!payment) {
      await markEvent(conn, event.id, "ignored", "Missing normalized user/loan/amount fields");
      await createAudit(conn, "WEBHOOK_EVENT_IGNORED", event.id, {
        dry_run: DRY_RUN,
        webhook_event_id: event.id,
        reason: "missing normalized fields"
      });
      await completeJob(conn, eventKey, runDate);
      return { processed: 0, ignored: 1, failed: 0, skipped: 0 };
    }

    const loan = await loadLoan(conn, payment.loan_id);
    if (!loan || Number(loan.user_id) !== Number(payment.user_id)) {
      await markEvent(conn, event.id, "failed", "Loan/user mismatch");
      await failJob(conn, eventKey, runDate, "Loan/user mismatch");
      return { processed: 0, ignored: 0, failed: 1, skipped: 0 };
    }
    if (!["active", "overdue"].includes(String(loan.status || ""))) {
      await markEvent(conn, event.id, "ignored", `Loan status not payable (${loan.status})`);
      await createAudit(conn, "WEBHOOK_EVENT_IGNORED", event.id, {
        dry_run: DRY_RUN,
        webhook_event_id: event.id,
        reason: `loan status not payable (${loan.status})`
      });
      await completeJob(conn, eventKey, runDate);
      return { processed: 0, ignored: 1, failed: 0, skipped: 0 };
    }

    if (await paymentRefExists(conn, payment.payment_reference)) {
      await markEvent(conn, event.id, "ignored", "Duplicate payment reference");
      await createAudit(conn, "WEBHOOK_EVENT_DUPLICATE", event.id, {
        dry_run: DRY_RUN,
        webhook_event_id: event.id,
        payment_reference: payment.payment_reference
      });
      await completeJob(conn, eventKey, runDate);
      return { processed: 0, ignored: 1, failed: 0, skipped: 0 };
    }

    let repaymentId = null;
    if (!DRY_RUN) {
      repaymentId = await insertRepayment(conn, payment);
    }

    await markEvent(conn, event.id, "processed", null);
    await createAudit(conn, "WEBHOOK_EVENT_PROCESSED", event.id, {
      dry_run: DRY_RUN,
      webhook_event_id: event.id,
      repayment_id: repaymentId,
      user_id: payment.user_id,
      loan_id: payment.loan_id,
      payment_reference: payment.payment_reference
    });
    await completeJob(conn, eventKey, runDate);
    return { processed: 1, ignored: 0, failed: 0, skipped: 0 };
  } catch (err) {
    await markEvent(conn, event.id, "failed", String(err.message || "Unknown worker error").slice(0, 1000));
    await failJob(conn, eventKey, runDate, err.message || "Unknown worker error");
    return { processed: 0, ignored: 0, failed: 1, skipped: 0 };
  }
}

async function processBatch(pool) {
  const conn = await pool.getConnection();
  const runDate = toDateYmd(new Date());
  let processed = 0;
  let ignored = 0;
  let failed = 0;
  let skipped = 0;

  try {
    const events = await fetchPendingEvents(conn);
    for (const event of events) {
      const stats = await processEvent(conn, event, runDate);
      processed += stats.processed;
      ignored += stats.ignored;
      failed += stats.failed;
      skipped += stats.skipped;
    }
  } finally {
    conn.release();
  }

  return { processed, ignored, failed, skipped };
}

async function main() {
  const pool = createPool();
  console.log(`[${nowIso()}] ${JOB_TYPE} started (dry_run=${DRY_RUN}, once=${ONCE})`);

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

