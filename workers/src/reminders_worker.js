const { createPool } = require("./db");

const JOB_TYPE = "reminders_escalations";
const ONCE = process.argv.includes("--once");
const LOOP_SECONDS = Number(process.env.WORKER_REMINDERS_INTERVAL_SECONDS || 60);
const DRY_RUN = String(process.env.WORKER_REMINDERS_DRY_RUN || "true").toLowerCase() !== "false";
const REMINDER_WINDOW_DAYS = Number(process.env.WORKER_REMINDER_WINDOW_DAYS || 2);
const ESCALATION_GRACE_DAYS = Number(process.env.WORKER_ESCALATION_GRACE_DAYS || 3);

function nowIso() {
  return new Date().toISOString();
}

function toDateYmd(date) {
  return date.toISOString().slice(0, 10);
}

function dateFromSql(value) {
  if (value instanceof Date) {
    return value;
  }
  return new Date(`${value}T00:00:00`);
}

function daysBetween(fromDate, toDate) {
  const ms = toDate.getTime() - fromDate.getTime();
  return Math.floor(ms / 86400000);
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

async function createNotification(conn, item) {
  const [res] = await conn.execute(
    `INSERT INTO notifications
      (user_id, notification_type, title, message, is_read, sent_via, related_loan_id, created_at)
     VALUES (?, ?, ?, ?, 0, 'in_app', ?, NOW())`,
    [item.user_id, item.notification_type, item.title, item.message, item.loan_id]
  );
  return res.insertId;
}

async function createAudit(conn, action, entityId, meta) {
  await conn.execute(
    `INSERT INTO audit_log
      (user_id, action, entity_type, entity_id, old_values, new_values, ip_address, user_agent, created_at)
     VALUES (NULL, ?, 'worker_job_runs', ?, NULL, ?, '127.0.0.1', 'node-worker/reminders', NOW())`,
    [action, entityId || null, JSON.stringify(meta)]
  );
}

async function fetchCandidates(conn) {
  const [rows] = await conn.execute(
    `SELECT
       rs.schedule_id,
       rs.loan_id,
       rs.due_date,
       rs.status AS schedule_status,
       rs.amount_due,
       rs.amount_paid,
       l.user_id,
       l.status AS loan_status,
       l.outstanding_balance_mwk,
       u.full_name
     FROM repayment_schedule rs
     JOIN loans l ON l.loan_id = rs.loan_id
     JOIN users u ON u.user_id = l.user_id
     WHERE rs.status IN ('pending', 'overdue', 'partial')
       AND l.status IN ('active', 'overdue')
       AND DATE(rs.due_date) <= DATE_ADD(CURDATE(), INTERVAL ? DAY)
     ORDER BY rs.due_date ASC`,
    [REMINDER_WINDOW_DAYS]
  );
  return rows;
}

function classifyItem(item, today) {
  const due = dateFromSql(item.due_date);
  const daysToDue = daysBetween(today, due);

  if (daysToDue >= 0 && daysToDue <= REMINDER_WINDOW_DAYS) {
    return {
      eventType: "reminder",
      eventKey: `reminder:${item.schedule_id}:d${daysToDue}`,
      notification_type: "reminder",
      title: "Upcoming Repayment Reminder",
      message: `Hello ${item.full_name}, your repayment is due in ${daysToDue} day(s).`
    };
  }

  if (daysToDue < 0) {
    const overdueDays = Math.abs(daysToDue);
    if (overdueDays >= ESCALATION_GRACE_DAYS) {
      return {
        eventType: "escalation",
        eventKey: `escalation:${item.schedule_id}:d${overdueDays}`,
        notification_type: "overdue",
        title: "Repayment Overdue",
        message: `Hello ${item.full_name}, your repayment is overdue by ${overdueDays} day(s).`
      };
    }
  }

  return null;
}

async function processBatch(pool) {
  const conn = await pool.getConnection();
  let processed = 0;
  let skipped = 0;
  const today = new Date(`${toDateYmd(new Date())}T00:00:00`);
  const runDate = toDateYmd(today);

  try {
    const candidates = await fetchCandidates(conn);
    for (const item of candidates) {
      const classification = classifyItem(item, today);
      if (!classification) {
        skipped += 1;
        continue;
      }

      const payload = {
        schedule_id: item.schedule_id,
        loan_id: item.loan_id,
        user_id: item.user_id,
        event_type: classification.eventType
      };

      const reserved = await reserveJob(conn, classification.eventKey, runDate, payload);
      if (!reserved) {
        skipped += 1;
        continue;
      }

      try {
        let notificationId = null;
        if (!DRY_RUN) {
          notificationId = await createNotification(conn, {
            ...classification,
            user_id: item.user_id,
            loan_id: item.loan_id
          });
        }

        await createAudit(conn, "WORKER_REMINDER_PROCESSED", notificationId, {
          dry_run: DRY_RUN,
          event_key: classification.eventKey,
          schedule_id: item.schedule_id,
          loan_id: item.loan_id,
          user_id: item.user_id
        });

        await completeJob(conn, classification.eventKey, runDate);
        processed += 1;
      } catch (err) {
        await failJob(conn, classification.eventKey, runDate, err.message || "Unknown worker error");
      }
    }
  } finally {
    conn.release();
  }

  return { processed, skipped };
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

