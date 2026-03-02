<?php
/**
 * Credit Scoring Engine (MLW-v1.0)
 * Integrated into Lemelani Loans application
 */

class CreditScoringEngine
{
    // Score scale
    const SCORE_MIN = 300;
    const SCORE_MAX = 850;

    // Component maxima (sum -> 550)
    const MAX_PAYMENT_HISTORY    = 200;
    const MAX_CREDIT_UTILIZATION = 100;
    const MAX_LOAN_HISTORY       = 75;
    const MAX_INCOME_STABILITY   = 100;
    const MAX_ALTERNATIVE_DATA   = 75;

    const TIERS = [
        'exceptional' => 740,
        'very_good'   => 670,
        'good'        => 580,
        'fair'        => 450,
        'poor'        => 300,
    ];

    const RATE_ADJUSTMENTS = [
        'exceptional' => -5.0,
        'very_good'   => -3.0,
        'good'        =>  0.0,
        'fair'        =>  5.0,
        'poor'        => 12.0,
    ];

    /** @var PDO */
    private $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    public function assessUser($userId)
    {
        $data = $this->gatherUserData($userId);
        if (!$data) {
            throw new RuntimeException("User #$userId not found or profile incomplete.");
        }

        $paymentScore     = $this->scorePaymentHistory($data);
        $utilizationScore = $this->scoreCreditUtilization($data);
        $loanHistScore    = $this->scoreLoanHistory($data);
        $incomeScore      = $this->scoreIncomeStability($data);
        $altDataScore     = $this->scoreAlternativeData($data);

        $totalPoints = $paymentScore + $utilizationScore + $loanHistScore + $incomeScore + $altDataScore;

        $finalScore = self::SCORE_MIN + $totalPoints;
        if ($finalScore < self::SCORE_MIN) $finalScore = self::SCORE_MIN;
        if ($finalScore > self::SCORE_MAX) $finalScore = self::SCORE_MAX;

        $tier = $this->determineTier($finalScore);

        $scoreId = $this->persistScore($userId, [
            'total_score'              => $finalScore,
            'credit_tier'              => $tier,
            'payment_history_score'    => $paymentScore,
            'credit_utilization_score' => $utilizationScore,
            'loan_history_score'       => $loanHistScore,
            'income_stability_score'   => $incomeScore,
            'alternative_data_score'   => $altDataScore,
        ]);

        $this->logAudit($userId, 'credit_score_calculated', 'credit_scores', $scoreId, null, ['score' => $finalScore, 'tier' => $tier]);

        return [
            'score_id'        => $scoreId,
            'user_id'         => $userId,
            'total_score'     => $finalScore,
            'credit_tier'     => $tier,
            'tier_label'      => ucfirst(str_replace('_', ' ', $tier)),
            'rate_adjustment' => self::RATE_ADJUSTMENTS[$tier],
            'breakdown' => [
                'payment_history'    => ['score' => $paymentScore, 'max' => self::MAX_PAYMENT_HISTORY],
                'credit_utilization' => ['score' => $utilizationScore, 'max' => self::MAX_CREDIT_UTILIZATION],
                'loan_history'       => ['score' => $loanHistScore, 'max' => self::MAX_LOAN_HISTORY],
                'income_stability'   => ['score' => $incomeScore, 'max' => self::MAX_INCOME_STABILITY],
                'alternative_data'   => ['score' => $altDataScore, 'max' => self::MAX_ALTERNATIVE_DATA],
            ],
            'tips' => $this->generateTips($data, $paymentScore, $utilizationScore, $incomeScore, $altDataScore),
        ];
    }

    private function scorePaymentHistory(array $data)
    {
        $repayments = isset($data['repayments']) ? $data['repayments'] : [];
        if (empty($repayments)) {
            return (int) round(self::MAX_PAYMENT_HISTORY * 0.40);
        }

        $total = count($repayments);
        $onTime = 0;
        $lateDays = 0;
        $missed = 0;

        foreach ($repayments as $r) {
            $status = isset($r['status']) ? $r['status'] : '';
            if ($status === 'on_time') $onTime++;
            elseif ($status === 'late') $lateDays += (int)($r['days_late'] ?? 0);
            else $missed++;
        }

        $onTimeRate = $onTime / max(1, $total);
        $base = $onTimeRate * self::MAX_PAYMENT_HISTORY;
        $base -= ($missed * 15);
        $base -= floor($lateDays / 30) * 5;
        $score = (int) round($base);
        if ($score < 0) $score = 0;
        if ($score > self::MAX_PAYMENT_HISTORY) $score = self::MAX_PAYMENT_HISTORY;
        return $score;
    }

    private function scoreCreditUtilization(array $data)
    {
        $totalOutstanding = (float) ($data['total_outstanding_balance'] ?? 0);
        $monthlyIncome = (float) ($data['monthly_income_mwk'] ?? 1);
        $annualIncome = $monthlyIncome * 12;
        if ($annualIncome <= 0) return 0;
        $utilizationRatio = $totalOutstanding / $annualIncome;
        if ($utilizationRatio <= 0.20)      $score = self::MAX_CREDIT_UTILIZATION;
        elseif ($utilizationRatio <= 0.40)  $score = (int) round(self::MAX_CREDIT_UTILIZATION * 0.80);
        elseif ($utilizationRatio <= 0.60)  $score = (int) round(self::MAX_CREDIT_UTILIZATION * 0.55);
        elseif ($utilizationRatio <= 0.80)  $score = (int) round(self::MAX_CREDIT_UTILIZATION * 0.30);
        else                                $score = (int) round(self::MAX_CREDIT_UTILIZATION * 0.10);
        if ($score < 0) $score = 0;
        if ($score > self::MAX_CREDIT_UTILIZATION) $score = self::MAX_CREDIT_UTILIZATION;
        return $score;
    }

    private function scoreLoanHistory(array $data)
    {
        $completedLoans = (int) ($data['completed_loans'] ?? 0);
        $defaultedLoans = (int) ($data['defaulted_loans'] ?? 0);
        $totalLoans = (int) ($data['total_loans'] ?? 0);
        if ($totalLoans === 0) return (int) round(self::MAX_LOAN_HISTORY * 0.40);
        $completionRate = $completedLoans / max(1, ($completedLoans + $defaultedLoans));
        $score = $completionRate * self::MAX_LOAN_HISTORY;
        if ($completedLoans >= 5) $score += 10;
        elseif ($completedLoans >= 3) $score += 5;
        $score -= ($defaultedLoans * 20);
        $score = (int) round($score);
        if ($score < 0) $score = 0;
        if ($score > self::MAX_LOAN_HISTORY) $score = self::MAX_LOAN_HISTORY;
        return $score;
    }

    private function scoreIncomeStability(array $data)
    {
        $employmentType = $data['employment_type'] ?? 'unemployed';
        $monthlyIncome = (float) ($data['monthly_income_mwk'] ?? 0);
        $employmentScores = [
            'employed' => 80,
            'business_owner' => 70,
            'self_employed' => 55,
            'student' => 30,
            'unemployed' => 10,
        ];
        $base = isset($employmentScores[$employmentType]) ? $employmentScores[$employmentType] : 10;
        if ($monthlyIncome >= 500000) $base += 20;
        elseif ($monthlyIncome >= 200000) $base += 15;
        elseif ($monthlyIncome >= 75000) $base += 10;
        elseif ($monthlyIncome >= 30000) $base += 5;
        $yearsAtAddress = (int) ($data['years_at_address'] ?? 0);
        if ($yearsAtAddress >= 3) $base += 5;
        elseif ($yearsAtAddress >= 1) $base += 2;
        if ($base < 0) $base = 0;
        if ($base > self::MAX_INCOME_STABILITY) $base = self::MAX_INCOME_STABILITY;
        return (int) $base;
    }

    private function scoreAlternativeData(array $data)
    {
        $altRecords = $data['alternative_data'] ?? [];
        if (empty($altRecords)) return (int) round(self::MAX_ALTERNATIVE_DATA * 0.20);
        $score = 0;
        foreach ($altRecords as $record) {
            $onTimeRate = (float) ($record['on_time_payment_rate'] ?? 0);
            $months = (int) ($record['months_of_history'] ?? 0);
            $type = $record['data_type'] ?? 'other';
            switch ($type) {
                case 'mobile_money': $typeWeight = 1.2; break;
                case 'utility_payment': $typeWeight = 1.0; break;
                case 'rent_payment': $typeWeight = 0.9; break;
                case 'savings': $typeWeight = 0.8; break;
                default: $typeWeight = 0.7; break;
            }
            $recordScore = ($onTimeRate / 100.0) * 35.0;
            if ($months >= 12) $recordScore += 5;
            elseif ($months >= 6) $recordScore += 2;
            $score += $recordScore * $typeWeight;
        }
        $score = (int) round($score);
        if ($score < 0) $score = 0;
        if ($score > self::MAX_ALTERNATIVE_DATA) $score = self::MAX_ALTERNATIVE_DATA;
        return $score;
    }

    private function determineTier($score)
    {
        foreach (self::TIERS as $tier => $minScore) {
            if ($score >= $minScore) return $tier;
        }
        return 'poor';
    }

    private function getEligibleProducts($score)
    {
        $stmt = $this->db->prepare(
            "SELECT id, product_name, min_amount_mwk, max_amount_mwk, min_term_months, max_term_months, base_interest_rate
             FROM loan_products
             WHERE is_active = 1 AND min_credit_score <= :score
             ORDER BY base_interest_rate ASC"
        );
        $stmt->execute([':score' => $score]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function calculateEffectiveRate($baseRate, $creditTier)
    {
        $adjustment = isset(self::RATE_ADJUSTMENTS[$creditTier]) ? self::RATE_ADJUSTMENTS[$creditTier] : 0;
        $effectiveRate = $baseRate + $adjustment;
        return max(10.0, round($effectiveRate, 2));
    }

    public function calculateEMI($principal, $annualRate, $termMonths)
    {
        if ($termMonths <= 0) return 0;
        if ($annualRate <= 0) return round($principal / $termMonths, 2);
        $monthlyRate = ($annualRate / 100.0) / 12.0;
        $emi = $principal * $monthlyRate * pow(1 + $monthlyRate, $termMonths) / (pow(1 + $monthlyRate, $termMonths) - 1);
        return round($emi, 2);
    }

    public function evaluateApplication($userId, $productId, $requestedAmount, $termMonths)
    {
        $stmt = $this->db->prepare("SELECT * FROM credit_scores WHERE user_id = :uid ORDER BY assessed_at DESC LIMIT 1");
        $stmt->execute([':uid' => $userId]);
        $score = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$score) $score = $this->assessUser($userId);

        $stmt2 = $this->db->prepare("SELECT * FROM loan_products WHERE id = :pid AND is_active = 1");
        $stmt2->execute([':pid' => $productId]);
        $product = $stmt2->fetch(PDO::FETCH_ASSOC);
        if (!$product) throw new RuntimeException("Loan product not found.");

        $totalScore = (int) ($score['total_score'] ?? $score['total_score'] ?? self::SCORE_MIN);
        $tier = $score['credit_tier'] ?? 'fair';

        $decision = 'approved';
        $reasons = [];
        $approvedAmt = $requestedAmount;

        if ($totalScore < $product['min_credit_score']) {
            $decision = 'rejected';
            $reasons[] = "Credit score ({$totalScore}) below minimum required ({$product['min_credit_score']}).";
        }

        $stmt3 = $this->db->prepare("SELECT account_status FROM users WHERE user_id = :uid");
        $stmt3->execute([':uid' => $userId]);
        $userStatus = $stmt3->fetchColumn();
        if ($userStatus !== 'active') {
            $decision = 'rejected';
            $reasons[] = "Account status: {$userStatus}.";
        }

        if ($requestedAmount > $product['max_amount_mwk']) {
            $approvedAmt = (float) $product['max_amount_mwk'];
            $reasons[] = "Amount capped at product maximum (MWK " . number_format($approvedAmt) . ").";
        }
        if ($requestedAmount < $product['min_amount_mwk']) {
            $decision = 'rejected';
            $reasons[] = "Requested amount below product minimum (MWK " . number_format($product['min_amount_mwk']) . ").";
        }

        if ($decision === 'approved' && in_array($tier, ['fair', 'poor'])) {
            $tierCaps = ['fair' => 0.70, 'poor' => 0.40];
            $capRatio = $tierCaps[$tier];
            $cappedAmount = $product['max_amount_mwk'] * $capRatio;
            if ($requestedAmount > $cappedAmount) {
                $approvedAmt = $cappedAmount;
                $reasons[] = "Amount reduced to MWK " . number_format($approvedAmt) . " due to credit tier ({$tier}).";
            }
        }

        $stmtDef = $this->db->prepare("SELECT COUNT(*) FROM loans WHERE user_id = :uid AND status = 'defaulted'");
        $stmtDef->execute([':uid' => $userId]);
        if ($stmtDef->fetchColumn() > 0) {
            $decision = 'rejected';
            $reasons[] = "Active loan default on record.";
        }

        $effectiveRate = $this->calculateEffectiveRate((float)$product['base_interest_rate'], $tier);
        $monthlyEMI = ($decision === 'approved') ? $this->calculateEMI($approvedAmt, $effectiveRate, $termMonths) : 0;

        return [
            'decision' => $decision,
            'user_id' => $userId,
            'product_id' => $productId,
            'credit_score' => $totalScore,
            'credit_tier' => $tier,
            'requested_amount' => $requestedAmount,
            'approved_amount' => $decision === 'approved' ? round($approvedAmt, 2) : 0,
            'interest_rate' => $effectiveRate,
            'term_months' => $termMonths,
            'monthly_emi_mwk' => $monthlyEMI,
            'total_repayable' => round($monthlyEMI * $termMonths, 2),
            'reasons' => $reasons,
            'score_id' => $score['id'] ?? null,
        ];
    }

    private function generateTips(array $data, $pScore, $uScore, $iScore, $aScore)
    {
        $tips = [];
        if ($pScore < self::MAX_PAYMENT_HISTORY * 0.7) {
            $tips[] = "Make all loan repayments on time. Payment history is your most important score factor.";
        }
        if ($uScore < self::MAX_CREDIT_UTILIZATION * 0.6) {
            $tips[] = "Reduce outstanding loan balances relative to your income to improve utilization score.";
        }
        if ($iScore < self::MAX_INCOME_STABILITY * 0.6) {
            $tips[] = "Formalise your employment or income documentation to improve stability.";
        }
        if ($aScore < self::MAX_ALTERNATIVE_DATA * 0.5) {
            $tips[] = "Register and use mobile money regularly — it helps alternative data scoring.";
        }
        if (empty($data['repayments'])) $tips[] = "Consider starting with a small Emergency Loan to build history.";
        return $tips;
    }

    private function gatherUserData($userId)
    {
        $stmt = $this->db->prepare(
            "SELECT u.*, up.employment_type, up.monthly_income_mwk, up.years_at_address, up.district
             FROM users u
             LEFT JOIN user_profiles up ON up.user_id = u.user_id
             WHERE u.user_id = :uid"
        );
        $stmt->execute([':uid' => $userId]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$user) return false;

        $stmt2 = $this->db->prepare(
            "SELECT r.status, r.days_late, r.paid_at
             FROM repayments r
             JOIN loans l ON l.loan_id = r.loan_id
             WHERE l.user_id = :uid
             ORDER BY r.paid_at DESC"
        );
        $stmt2->execute([':uid' => $userId]);
        $user['repayments'] = $stmt2->fetchAll(PDO::FETCH_ASSOC);

        $stmt3 = $this->db->prepare(
            "SELECT COUNT(*) AS total_loans,
                    SUM(CASE WHEN status='completed' THEN 1 ELSE 0 END) AS completed_loans,
                    SUM(CASE WHEN status='defaulted' THEN 1 ELSE 0 END) AS defaulted_loans,
                    COALESCE(SUM(CASE WHEN status='active' THEN outstanding_balance_mwk ELSE 0 END),0) AS total_outstanding_balance
             FROM loans WHERE user_id = :uid"
        );
        $stmt3->execute([':uid' => $userId]);
        $loanSummary = $stmt3->fetch(PDO::FETCH_ASSOC);
        $user = array_merge($user, $loanSummary ?: []);

        $stmt4 = $this->db->prepare(
            "SELECT data_type, provider, avg_monthly_transactions, months_of_history, on_time_payment_rate
             FROM alternative_credit_data WHERE user_id = :uid"
        );
        $stmt4->execute([':uid' => $userId]);
        $user['alternative_data'] = $stmt4->fetchAll(PDO::FETCH_ASSOC);

        return $user;
    }

    private function persistScore($userId, array $scoreData)
    {
        $stmt = $this->db->prepare(
            "INSERT INTO credit_scores (user_id, total_score, credit_tier, payment_history_score, credit_utilization_score, loan_history_score, income_stability_score, alternative_data_score, scoring_model_version)
             VALUES (:uid, :total, :tier, :ph, :cu, :lh, :is, :ad, 'MLW-v1.0')"
        );
        $stmt->execute([
            ':uid' => $userId,
            ':total' => $scoreData['total_score'],
            ':tier' => $scoreData['credit_tier'],
            ':ph' => $scoreData['payment_history_score'],
            ':cu' => $scoreData['credit_utilization_score'],
            ':lh' => $scoreData['loan_history_score'],
            ':is' => $scoreData['income_stability_score'],
            ':ad' => $scoreData['alternative_data_score'],
        ]);
        return (int) $this->db->lastInsertId();
    }

    private function logAudit($userId, $action, $table, $recordId, $old, $new)
    {
        $stmt = $this->db->prepare(
            "INSERT INTO audit_log (user_id, action, entity_type, entity_id, old_values, new_values, ip_address, user_agent)
             VALUES (:uid, :action, :etype, :eid, :old, :new, :ip, :ua)"
        );
        $stmt->execute([
            ':uid' => $userId,
            ':action' => $action,
            ':etype' => $table,
            ':eid' => $recordId,
            ':old' => $old ? json_encode($old) : null,
            ':new' => $new ? json_encode($new) : null,
            ':ip' => $_SERVER['REMOTE_ADDR'] ?? null,
            ':ua' => $_SERVER['HTTP_USER_AGENT'] ?? null,
        ]);
    }
}

?>
