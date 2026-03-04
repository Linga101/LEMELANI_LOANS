const { createPool } = require("./db");

const JOB_TYPE = "scoring_refresh_batch";
const SCORING_MODEL_VERSION = "MLW-v1.0";
const ONCE = process.argv.includes("--once");
const LOOP_SECONDS = Number(process.env.WORKER_SCORING_INTERVAL_SECONDS || 600);
const DRY_RUN = String(process.env.WORKER_SCORING_DRY_RUN || "true").toLowerCase() !== "false";
const STALE_DAYS = Number(process.env.WORKER_SCORING_STALE_DAYS || 30);
const BATCH_LIMIT = Number(process.env.WORKER_SCORING_BATCH_LIMIT || 200);

const SCORE_MIN = 300;
const SCORE_MAX = 850;
const MAX_PAYMENT_HISTORY = 200;
const MAX_CREDIT_UTILIZATION = 100;
const MAX_LOAN_HISTORY = 75;
const MAX_INCOME_STABILITY = 100;
const MAX_ALTERNATIVE_DATA = 75;

const TIERS = [
  ["exceptional", 740],
  ["very_good", 670],
  ["good", 580],
  ["fair", 450],
  ["poor", 300]
];

function nowIso() {
  return new Date().toISOString();
}

function toDateYmd(date) {
  return date.toISOString().slice(0, 10);
}

function intOrZero(v) {
  return Number.isFinite(Number(v)) ? Number(v) : 0;
}

function clamp(val, min, max) {
  return Math.max(min, Math.min(max, val));
}

function determineTier(score) {
  for (const [tier, minScore] of TIERS) {
    if (score >= minScore) return tier;
  }
  return "poor";
}

function scorePaymentHistory(repayments) {
  if (!repayments || repayments.length === 0) {
    return Math.round(MAX_PAYMENT_HISTORY * 0.4);
  }

  const total = repayments.length;
  let onTime = 0;
  let lateDays = 0;
  let missed = 0;

  for (const r of repayments) {
    const status = r.status || "";
    if (status === "on_time") onTime += 1;
    else if (status === "late") lateDays += intOrZero(r.days_late);
    else missed += 1;
  }

  const onTimeRate = onTime / Math.max(1, total);
  let base = onTimeRate * MAX_PAYMENT_HISTORY;
  base -= missed * 15;
  base -= Math.floor(lateDays / 30) * 5;
  return clamp(Math.round(base), 0, MAX_PAYMENT_HISTORY);
}

function scoreCreditUtilization(totalOutstandingBalance, monthlyIncomeMwk) {
  const annualIncome = Number(monthlyIncomeMwk || 0) * 12;
  if (annualIncome <= 0) return 0;

  const utilizationRatio = Number(totalOutstandingBalance || 0) / annualIncome;
  let score = 0;
  if (utilizationRatio <= 0.2) score = MAX_CREDIT_UTILIZATION;
  else if (utilizationRatio <= 0.4) score = Math.round(MAX_CREDIT_UTILIZATION * 0.8);
  else if (utilizationRatio <= 0.6) score = Math.round(MAX_CREDIT_UTILIZATION * 0.55);
  else if (utilizationRatio <= 0.8) score = Math.round(MAX_CREDIT_UTILIZATION * 0.3);
  else score = Math.round(MAX_CREDIT_UTILIZATION * 0.1);
  return clamp(score, 0, MAX_CREDIT_UTILIZATION);
}

function scoreLoanHistory(totalLoans, completedLoans, defaultedLoans) {
  if (intOrZero(totalLoans) === 0) {
    return Math.round(MAX_LOAN_HISTORY * 0.4);
  }

  const completionRate = intOrZero(completedLoans) / Math.max(1, intOrZero(completedLoans) + intOrZero(defaultedLoans));
  let score = completionRate * MAX_LOAN_HISTORY;
  if (intOrZero(completedLoans) >= 5) score += 10;
  else if (intOrZero(completedLoans) >= 3) score += 5;
  score -= intOrZero(defaultedLoans) * 20;
  return clamp(Math.round(score), 0, MAX_LOAN_HISTORY);
}

function scoreIncomeStability(employmentType, monthlyIncomeMwk, yearsAtAddress) {
  const employmentScores = {
    employed: 80,
    business_owner: 70,
    self_employed: 55,
    student: 30,
    unemployed: 10
  };

  let base = employmentScores[employmentType] ?? 10;
  const income = Number(monthlyIncomeMwk || 0);
  if (income >= 500000) base += 20;
  else if (income >= 200000) base += 15;
  else if (income >= 75000) base += 10;
  else if (income >= 30000) base += 5;

  const years = intOrZero(yearsAtAddress);
  if (years >= 3) base += 5;
  else if (years >= 1) base += 2;

  return clamp(Math.round(base), 0, MAX_INCOME_STABILITY);
}

function scoreAlternativeData(records) {
  if (!records || records.length === 0) {
    return Math.round(MAX_ALTERNATIVE_DATA * 0.2);
  }

  let score = 0;
  for (const record of records) {
    const onTimeRate = Number(record.on_time_payment_rate || 0);
    const months = intOrZero(record.months_of_history);
    const type = record.data_type || "other";

    let typeWeight = 0.7;
    if (type === "mobile_money") typeWeight = 1.2;
    else if (type === "utility_payment") typeWeight = 1.0;
    else if (type === "rent_payment") typeWeight = 0.9;
    else if (type === "savings") typeWeight = 0.8;

    let recordScore = (onTimeRate / 100.0) * 35.0;
    if (months >= 12) recordScore += 5;
    else if (months >= 6) recordScore += 2;

    score += recordScore * typeWeight;
  }

  return clamp(Math.round(score), 0, MAX_ALTERNATIVE_DATA);
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
     VALUES (?, ?, 'worker_job_runs', ?, NULL, ?, '127.0.0.1', 'node-worker/scoring-refresh', NOW())`,
    [meta.user_id || null, action, entityId || null, JSON.stringify(meta)]
  );
}

async function fetchCandidates(conn) {
  const [rows] = await conn.execute(
    `SELECT c.user_id, c.credit_score, c.account_status, c.last_assessed_at
     FROM (
       SELECT u.user_id, u.credit_score, u.account_status,
              MAX(cs.assessed_at) AS last_assessed_at
       FROM users u
       LEFT JOIN credit_scores cs ON cs.user_id = u.user_id
       WHERE u.role = 'customer' AND u.account_status = 'active'
       GROUP BY u.user_id, u.credit_score, u.account_status
     ) c
     WHERE c.last_assessed_at IS NULL
        OR DATE(c.last_assessed_at) <= DATE_SUB(CURDATE(), INTERVAL ? DAY)
     ORDER BY COALESCE(c.last_assessed_at, '1970-01-01') ASC, c.user_id ASC
     LIMIT ?`,
    [STALE_DAYS, BATCH_LIMIT]
  );
  return rows;
}

async function gatherUserData(conn, userId) {
  const [users] = await conn.execute(
    `SELECT u.user_id, u.credit_score, up.employment_type, up.monthly_income_mwk, up.years_at_address
     FROM users u
     LEFT JOIN user_profiles up ON up.user_id = u.user_id
     WHERE u.user_id = ?
     LIMIT 1`,
    [userId]
  );
  if (users.length === 0) return null;
  const user = users[0];

  const [repayments] = await conn.execute(
    `SELECT r.status, r.days_late, r.paid_at
     FROM repayments r
     JOIN loans l ON l.loan_id = r.loan_id
     WHERE l.user_id = ?
     ORDER BY r.paid_at DESC`,
    [userId]
  );

  const [loanSummaryRows] = await conn.execute(
    `SELECT COUNT(*) AS total_loans,
            SUM(CASE WHEN status='completed' THEN 1 ELSE 0 END) AS completed_loans,
            SUM(CASE WHEN status='defaulted' THEN 1 ELSE 0 END) AS defaulted_loans,
            COALESCE(SUM(CASE WHEN status='active' THEN outstanding_balance_mwk ELSE 0 END),0) AS total_outstanding_balance
     FROM loans WHERE user_id = ?`,
    [userId]
  );
  const loanSummary = loanSummaryRows[0] || {};

  const [altData] = await conn.execute(
    `SELECT data_type, provider, avg_monthly_transactions, months_of_history, on_time_payment_rate
     FROM alternative_credit_data
     WHERE user_id = ?`,
    [userId]
  );

  return {
    ...user,
    ...loanSummary,
    repayments,
    alternative_data: altData
  };
}

function computeScore(userData) {
  const paymentHistoryScore = scorePaymentHistory(userData.repayments);
  const creditUtilizationScore = scoreCreditUtilization(
    userData.total_outstanding_balance,
    userData.monthly_income_mwk
  );
  const loanHistoryScore = scoreLoanHistory(
    userData.total_loans,
    userData.completed_loans,
    userData.defaulted_loans
  );
  const incomeStabilityScore = scoreIncomeStability(
    userData.employment_type || "unemployed",
    userData.monthly_income_mwk,
    userData.years_at_address
  );
  const alternativeDataScore = scoreAlternativeData(userData.alternative_data);

  const totalPoints =
    paymentHistoryScore +
    creditUtilizationScore +
    loanHistoryScore +
    incomeStabilityScore +
    alternativeDataScore;
  const totalScore = clamp(SCORE_MIN + totalPoints, SCORE_MIN, SCORE_MAX);
  const creditTier = determineTier(totalScore);

  return {
    totalScore,
    creditTier,
    paymentHistoryScore,
    creditUtilizationScore,
    loanHistoryScore,
    incomeStabilityScore,
    alternativeDataScore
  };
}

async function persistScore(conn, userId, oldScore, score) {
  await conn.beginTransaction();
  try {
    const [insertRes] = await conn.execute(
      `INSERT INTO credit_scores
        (user_id, total_score, credit_tier, payment_history_score, credit_utilization_score, loan_history_score,
         income_stability_score, alternative_data_score, scoring_model_version, assessed_by, notes, assessed_at)
       VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'system', ?, NOW())`,
      [
        userId,
        score.totalScore,
        score.creditTier,
        score.paymentHistoryScore,
        score.creditUtilizationScore,
        score.loanHistoryScore,
        score.incomeStabilityScore,
        score.alternativeDataScore,
        SCORING_MODEL_VERSION,
        "Batch scoring refresh worker"
      ]
    );

    await conn.execute(
      `UPDATE users
       SET credit_score = ?
       WHERE user_id = ?`,
      [score.totalScore, userId]
    );

    await conn.execute(
      `INSERT INTO credit_history
        (user_id, event_type, old_score, new_score, score_change, description, created_at)
       VALUES (?, 'score_adjusted', ?, ?, ?, ?, NOW())`,
      [
        userId,
        oldScore,
        score.totalScore,
        score.totalScore - oldScore,
        "Score refreshed by Node worker"
      ]
    );

    await conn.commit();
    return insertRes.insertId;
  } catch (err) {
    await conn.rollback();
    throw err;
  }
}

async function processBatch(pool) {
  const conn = await pool.getConnection();
  let processed = 0;
  let skipped = 0;
  const runDate = toDateYmd(new Date());

  try {
    const candidates = await fetchCandidates(conn);
    for (const candidate of candidates) {
      const userId = Number(candidate.user_id);
      const eventKey = `score-refresh:user:${userId}:v1`;
      const payload = { user_id: userId, stale_days: STALE_DAYS, model: SCORING_MODEL_VERSION };

      const reserved = await reserveJob(conn, eventKey, runDate, payload);
      if (!reserved) {
        skipped += 1;
        continue;
      }

      try {
        const userData = await gatherUserData(conn, userId);
        if (!userData) {
          await failJob(conn, eventKey, runDate, "User not found while gathering data");
          skipped += 1;
          continue;
        }

        const oldScore = intOrZero(userData.credit_score);
        const score = computeScore(userData);
        let scoreRecordId = null;

        if (!DRY_RUN) {
          scoreRecordId = await persistScore(conn, userId, oldScore, score);
        }

        await createAudit(conn, "WORKER_SCORE_REFRESHED", scoreRecordId, {
          dry_run: DRY_RUN,
          user_id: userId,
          old_score: oldScore,
          new_score: score.totalScore,
          credit_tier: score.creditTier
        });

        await completeJob(conn, eventKey, runDate);
        processed += 1;
      } catch (err) {
        await failJob(conn, eventKey, runDate, err.message || "Unknown worker error");
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
