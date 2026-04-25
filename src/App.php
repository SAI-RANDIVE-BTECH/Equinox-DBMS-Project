<?php

declare(strict_types=1);

namespace Equinox;

use PDO;
use PDOStatement;
use RuntimeException;
use Throwable;

final class App
{
    private const WALLET_ACTIVATION_FEE_INR = 1500.00;
    private const CARD_ISSUANCE_FEE_INR = 1500.00;

    private ?PDO $db = null;

    public function run(): void
    {
        $method = $_SERVER['REQUEST_METHOD'];
        $path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?: '/';

        try {
            if ($path === '/' && $method === 'GET') {
                $this->serveFile(dirname(__DIR__) . '/templates/app.html', 'text/html; charset=UTF-8');
                return;
            }

            if ($path === '/favicon.ico' && $method === 'GET') {
                http_response_code(204);
                return;
            }

            if ($path === '/assets/styles.css' && $method === 'GET') {
                $this->serveFile(dirname(__DIR__) . '/templates/styles.css', 'text/css');
                return;
            }

            if ($path === '/assets/app.js' && $method === 'GET') {
                $this->serveFile(dirname(__DIR__) . '/templates/app.js', 'application/javascript');
                return;
            }

            if (!str_starts_with($path, '/api')) {
                http_response_code(404);
                echo 'Not Found';
                return;
            }

            $this->route($method, $path);
        } catch (Throwable $exception) {
            Response::json([
                'success' => false,
                'error' => $exception->getMessage(),
            ], 500);
        }
    }

    private function route(string $method, string $path): void
    {
        $input = $this->input();

        if ($method === 'GET' && $path === '/api/health') {
            Response::json([
                'success' => true,
                'message' => 'Equinox API is healthy',
                'timestamp' => date(DATE_ATOM),
                'razorpay_configured' => $this->isRazorpayConfigured(),
            ]);
            return;
        }

        if ($method === 'POST' && $path === '/api/register') {
            $this->register($input);
            return;
        }

        if ($method === 'POST' && $path === '/api/login') {
            $this->login($input);
            return;
        }

        if ($method === 'GET' && $path === '/api/bootstrap') {
            $this->bootstrap();
            return;
        }

        if ($method === 'POST' && $path === '/api/payments/create-order') {
            $this->createPaymentOrder($input);
            return;
        }

        if ($method === 'POST' && $path === '/api/payments/verify') {
            $this->verifyPayment($input);
            return;
        }

        if ($method === 'POST' && $path === '/api/payments/upi') {
            $this->handleUpiPayment($input);
            return;
        }

        if ($method === 'POST' && $path === '/api/wallet/activate') {
            Response::json([
                'success' => false,
                'error' => 'Wallet activation is now linked to a verified Razorpay payment. Use the payment button in the dashboard.',
            ], 405);
            return;
        }

        if ($method === 'POST' && $path === '/api/wallet/transfer') {
            $this->transferEq($input);
            return;
        }

        if ($method === 'POST' && $path === '/api/cards') {
            Response::json([
                'success' => false,
                'error' => 'Direct card generation is disabled. Complete the Rs. 1,500 payment flow first.',
            ], 405);
            return;
        }

        if ($method === 'POST' && $path === '/api/cards/freeze') {
            $this->toggleCardFreeze($input);
            return;
        }

        if ($method === 'POST' && $path === '/api/pools') {
            $this->createPool($input);
            return;
        }

        if ($method === 'POST' && $path === '/api/pools/contribute') {
            $this->contributePool($input);
            return;
        }

        if ($method === 'POST' && $path === '/api/skills') {
            $this->createSkill($input);
            return;
        }

        if ($method === 'POST' && $path === '/api/contracts') {
            $this->createContract($input);
            return;
        }

        if ($method === 'POST' && $path === '/api/contracts/settle') {
            $this->settleContract($input);
            return;
        }

        if ($method === 'POST' && $path === '/api/reviews') {
            $this->createReview($input);
            return;
        }

        if ($method === 'POST' && $path === '/api/query-demonstrator') {
            $this->queryDemonstrator($input);
            return;
        }

        if ($method === 'GET' && $path === '/api/stream') {
            $this->stream();
            return;
        }

        Response::json(['success' => false, 'error' => 'Endpoint not found'], 404);
    }

    private function register(array $input): void
    {
        $db = $this->db();
        $name = trim((string) ($input['full_name'] ?? ''));
        $email = trim((string) ($input['email'] ?? ''));
        $password = (string) ($input['password'] ?? '');
        $dob = trim((string) ($input['date_of_birth'] ?? ''));
        $phone = trim((string) ($input['phone_number'] ?? ''));
        $city = trim((string) ($input['location_city'] ?? ''));

        if ($name === '' || $email === '' || $password === '' || $dob === '') {
            Response::json(['success' => false, 'error' => 'Please complete all required fields before creating the account.'], 422);
            return;
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            Response::json(['success' => false, 'error' => 'Please enter a valid email address.'], 422);
            return;
        }

        if (strlen($password) < 6) {
            Response::json(['success' => false, 'error' => 'Password must be at least 6 characters long.'], 422);
            return;
        }

        if ($phone !== '' && !preg_match('/^\+?[0-9]{10,15}$/', $phone)) {
            Response::json(['success' => false, 'error' => 'Please enter a valid phone number.'], 422);
            return;
        }

        if (!$this->isDate($dob)) {
            Response::json(['success' => false, 'error' => 'Please enter a valid date of birth.'], 422);
            return;
        }

        $existingUser = $db->prepare('SELECT user_id FROM users_profile WHERE email = ?');
        $existingUser->execute([$email]);
        if ($existingUser->fetch()) {
            Response::json(['success' => false, 'error' => 'This email address is already registered.'], 409);
            return;
        }

        $userId = $this->uuid();
        $walletId = $this->uuid();

        $db->beginTransaction();
        try {
            $db->prepare(
                'INSERT INTO users_profile (
                    user_id, full_name, email, password_hash, phone_number, date_of_birth, location_city
                ) VALUES (?, ?, ?, ?, ?, ?, ?)'
            )->execute([
                $userId,
                $name,
                $email,
                password_hash($password, PASSWORD_DEFAULT),
                $phone ?: null,
                $dob,
                $city ?: null,
            ]);

            $db->prepare('INSERT INTO user_settings (user_id) VALUES (?)')->execute([$userId]);
            $db->prepare('INSERT INTO wallets_equinox (wallet_id, user_id) VALUES (?, ?)')->execute([$walletId, $userId]);

            $db->commit();
        } catch (Throwable $exception) {
            $db->rollBack();
            throw $exception;
        }

        Response::json([
            'success' => true,
            'message' => 'Your Equinox citizen account has been created. Sign in to continue.',
        ]);
    }

    private function login(array $input): void
    {
        $db = $this->db();
        Env::load(dirname(__DIR__) . '/.env');
        $secret = Env::get('JWT_SECRET', 'secret');
        $email = trim((string) ($input['email'] ?? ''));
        $password = (string) ($input['password'] ?? '');

        if ($email === '' || $password === '') {
            Response::json(['success' => false, 'error' => 'Email and password are required.'], 422);
            return;
        }

        $stmt = $db->prepare(
            'SELECT user_id, full_name, email, password_hash
             FROM users_profile
             WHERE email = ?'
        );
        $stmt->execute([$email]);
        $user = $stmt->fetch();

        if (!$user || !password_verify($password, (string) $user['password_hash'])) {
            Response::json(['success' => false, 'error' => 'Invalid email or password.'], 401);
            return;
        }

        $token = Jwt::encode([
            'sub' => $user['user_id'],
            'email' => $user['email'],
            'name' => $user['full_name'],
            'exp' => time() + (60 * 60 * 8),
        ], $secret);

        $this->recordLogin((string) $user['user_id']);

        Response::json([
            'success' => true,
            'token' => $token,
        ]);
    }

    private function bootstrap(): void
    {
        $db = $this->db();
        $auth = $this->requireUser();
        $userId = (string) $auth['sub'];

        $profileStmt = $db->prepare(
            'SELECT
                u.user_id,
                u.full_name,
                u.email,
                u.phone_number,
                u.location_city,
                u.kyc_status,
                u.trust_score,
                u.created_at,
                w.wallet_id,
                w.balance,
                w.status,
                w.activated_at
             FROM users_profile u
             JOIN wallets_equinox w ON w.user_id = u.user_id
             WHERE u.user_id = ?'
        );
        $profileStmt->execute([$userId]);
        $profile = $profileStmt->fetch();

        if (!$profile) {
            Response::json(['success' => false, 'error' => 'Citizen profile not found.'], 404);
            return;
        }

        $walletId = (string) $profile['wallet_id'];

        $insightsStmt = $db->prepare(
            'SELECT
                COALESCE(SUM(CASE WHEN sender_wallet = ? THEN amount ELSE 0 END), 0) AS total_sent_eq,
                COALESCE(SUM(CASE WHEN receiver_wallet = ? THEN amount ELSE 0 END), 0) AS total_received_eq,
                COUNT(*) AS ledger_count
             FROM transactions_master
             WHERE sender_wallet = ? OR receiver_wallet = ?'
        );
        $insightsStmt->execute([$walletId, $walletId, $walletId, $walletId]);
        $insights = $insightsStmt->fetch() ?: [];

        $paymentInsightStmt = $db->prepare(
            "SELECT
                COALESCE(SUM(CASE WHEN payment_status = 'PAID' THEN amount_inr ELSE 0 END), 0) AS fiat_spent_inr,
                COUNT(*) AS payment_order_count,
                SUM(CASE WHEN payment_status = 'PAID' THEN 1 ELSE 0 END) AS successful_payment_count
             FROM payment_orders
             WHERE user_id = ?"
        );
        $paymentInsightStmt->execute([$userId]);
        $paymentInsights = $paymentInsightStmt->fetch() ?: [];

        $transactions = $db->prepare(
            'SELECT
                t.transaction_id,
                t.sender_wallet,
                t.receiver_wallet,
                t.amount,
                t.transaction_type,
                t.status,
                t.note,
                t.latitude,
                t.longitude,
                t.created_at,
                sender.full_name AS sender_name,
                receiver.full_name AS receiver_name
             FROM transactions_master t
             LEFT JOIN wallets_equinox sw ON sw.wallet_id = t.sender_wallet
             LEFT JOIN users_profile sender ON sender.user_id = sw.user_id
             LEFT JOIN wallets_equinox rw ON rw.wallet_id = t.receiver_wallet
             LEFT JOIN users_profile receiver ON receiver.user_id = rw.user_id
             WHERE t.sender_wallet = ? OR t.receiver_wallet = ?
             ORDER BY t.created_at DESC
             LIMIT 14'
        );
        $transactions->execute([$walletId, $walletId]);

        $notifications = $db->prepare(
            'SELECT notif_id, title, body, notif_type, is_read, created_at
             FROM notifications
             WHERE user_id = ?
             ORDER BY created_at DESC
             LIMIT 12'
        );
        $notifications->execute([$userId]);

        $cards = $db->prepare(
            'SELECT card_id, wallet_id, issued_via_payment_order_id, card_number, expiry_date, card_name, is_frozen, created_at
             FROM equinox_cards
             WHERE wallet_id = ?
             ORDER BY created_at DESC'
        );
        $cards->execute([$walletId]);

        $payments = $db->prepare(
            'SELECT
                payment_order_id,
                payment_purpose,
                amount_inr,
                currency,
                payment_status,
                fulfillment_status,
                gateway_order_id,
                gateway_payment_id,
                gateway_status,
                receipt,
                resource_reference,
                metadata_json,
                failure_reason,
                paid_at,
                fulfilled_at,
                created_at,
                updated_at
             FROM payment_orders
             WHERE user_id = ?
             ORDER BY created_at DESC
             LIMIT 12'
        );
        $payments->execute([$userId]);

        $pools = $db->query(
            'SELECT
                p.pool_id,
                p.creator_id,
                p.title,
                p.description,
                p.target_amount,
                p.raised_amount,
                p.deadline,
                p.status,
                p.verified,
                p.created_at,
                u.full_name AS creator_name,
                COUNT(pc.contrib_id) AS contribution_count
             FROM community_pools p
             JOIN users_profile u ON u.user_id = p.creator_id
             LEFT JOIN pool_contributions pc ON pc.pool_id = p.pool_id
             GROUP BY
                p.pool_id, p.creator_id, p.title, p.description, p.target_amount, p.raised_amount,
                p.deadline, p.status, p.verified, p.created_at, u.full_name
             ORDER BY p.created_at DESC
             LIMIT 12'
        )->fetchAll();

        $skills = $db->query(
            'SELECT
                s.skill_id,
                s.user_id,
                s.skill_name,
                s.description,
                s.rate_per_hour,
                s.is_active,
                s.created_at,
                u.full_name,
                w.wallet_id AS provider_wallet
             FROM skill_listings s
             JOIN users_profile u ON u.user_id = s.user_id
             JOIN wallets_equinox w ON w.user_id = u.user_id
             WHERE s.is_active = 1
             ORDER BY s.created_at DESC
             LIMIT 12'
        )->fetchAll();

        $contracts = $db->prepare(
            'SELECT
                c.contract_id,
                c.provider_id,
                c.consumer_id,
                c.skill_id,
                c.hours,
                c.total_eq,
                c.status,
                c.created_at,
                provider.full_name AS provider_name,
                consumer.full_name AS consumer_name,
                s.skill_name
             FROM service_contracts c
             JOIN users_profile provider ON provider.user_id = c.provider_id
             JOIN users_profile consumer ON consumer.user_id = c.consumer_id
             JOIN skill_listings s ON s.skill_id = c.skill_id
             WHERE c.provider_id = ? OR c.consumer_id = ?
             ORDER BY c.created_at DESC'
        );
        $contracts->execute([$userId, $userId]);

        $trustHistory = $db->prepare(
            'SELECT log_id, score_delta, reason, created_at
             FROM trust_history
             WHERE user_id = ?
             ORDER BY created_at DESC
             LIMIT 10'
        );
        $trustHistory->execute([$userId]);

        $directory = $db->prepare(
            'SELECT
                u.user_id,
                u.full_name,
                u.location_city,
                u.kyc_status,
                u.trust_score,
                w.wallet_id,
                w.status AS wallet_status,
                COUNT(DISTINCT s.skill_id) AS skill_count
             FROM users_profile u
             JOIN wallets_equinox w ON w.user_id = u.user_id
             LEFT JOIN skill_listings s ON s.user_id = u.user_id AND s.is_active = 1
             WHERE u.user_id <> ?
             GROUP BY
                u.user_id, u.full_name, u.location_city, u.kyc_status, u.trust_score, w.wallet_id, w.status
             ORDER BY u.trust_score DESC, u.created_at DESC
             LIMIT 20'
        );
        $directory->execute([$userId]);

        $loginHistory = $db->prepare(
            'SELECT log_id, device_fingerprint, ip_address, login_status, created_at
             FROM login_history
             WHERE user_id = ?
             ORDER BY created_at DESC
             LIMIT 6'
        );
        $loginHistory->execute([$userId]);

        $cardCountStmt = $db->prepare('SELECT COUNT(*) AS total_cards FROM equinox_cards WHERE wallet_id = ?');
        $cardCountStmt->execute([$walletId]);
        $cardCount = (int) (($cardCountStmt->fetch()['total_cards'] ?? 0));

        Response::json([
            'success' => true,
            'profile' => $profile,
            'insights' => [
                'total_sent_eq' => (float) ($insights['total_sent_eq'] ?? 0),
                'total_received_eq' => (float) ($insights['total_received_eq'] ?? 0),
                'ledger_count' => (int) ($insights['ledger_count'] ?? 0),
                'card_count' => $cardCount,
                'account_age_days' => $this->daysSince((string) $profile['created_at']),
                'fiat_spent_inr' => (float) ($paymentInsights['fiat_spent_inr'] ?? 0),
                'payment_order_count' => (int) ($paymentInsights['payment_order_count'] ?? 0),
                'successful_payment_count' => (int) ($paymentInsights['successful_payment_count'] ?? 0),
            ],
            'payment_config' => [
                'razorpay_key_id' => $this->isRazorpayConfigured() ? $this->razorpayCredentials()['key_id'] : null,
                'wallet_activation_fee_inr' => self::WALLET_ACTIVATION_FEE_INR,
                'card_issuance_fee_inr' => self::CARD_ISSUANCE_FEE_INR,
                'currency' => 'INR',
                'checkout_enabled' => $this->isRazorpayConfigured(),
            ],
            'transactions' => $transactions->fetchAll(),
            'notifications' => $notifications->fetchAll(),
            'cards' => array_map(fn (array $card): array => $this->mapCardForDisplay($card), $cards->fetchAll()),
            'payments' => array_map(fn (array $payment): array => $this->mapPaymentOrderForDisplay($payment), $payments->fetchAll()),
            'pools' => array_map(fn (array $pool): array => $this->mapPoolForDisplay($pool), $pools),
            'skills' => $skills,
            'contracts' => $contracts->fetchAll(),
            'trust_history' => $trustHistory->fetchAll(),
            'directory' => $directory->fetchAll(),
            'login_history' => $loginHistory->fetchAll(),
            'query_presets' => $this->queryPresetMeta(),
        ]);
    }

    private function createPaymentOrder(array $input): void
    {
        $db = $this->db();
        $auth = $this->requireUser();
        $userId = (string) $auth['sub'];
        $purpose = strtoupper(trim((string) ($input['purpose'] ?? '')));

        if (!$this->isRazorpayConfigured()) {
            Response::json(['success' => false, 'error' => 'Razorpay keys are not configured. Please check the .env file.'], 500);
            return;
        }

        if (!in_array($purpose, ['WALLET_ACTIVATION', 'CARD_ISSUANCE'], true)) {
            Response::json(['success' => false, 'error' => 'Invalid payment purpose supplied.'], 422);
            return;
        }

        $profile = $this->fetchUserProfileForPayment($userId);
        $wallet = $this->findWalletForUser($userId);

        $metadata = [
            'card_name' => trim((string) ($input['card_name'] ?? 'Primary')),
            'latitude' => $input['latitude'] ?? null,
            'longitude' => $input['longitude'] ?? null,
        ];

        if ($purpose === 'WALLET_ACTIVATION' && (string) $wallet['status'] === 'ACTIVE') {
            Response::json(['success' => false, 'error' => 'This wallet is already active.'], 422);
            return;
        }

        if ($purpose === 'CARD_ISSUANCE') {
            if ((string) $wallet['status'] !== 'ACTIVE') {
                Response::json(['success' => false, 'error' => 'Activate the wallet before requesting a new card.'], 422);
                return;
            }

            $cardCountStmt = $db->prepare('SELECT COUNT(*) AS total_cards FROM equinox_cards WHERE wallet_id = ?');
            $cardCountStmt->execute([$wallet['wallet_id']]);
            $totalCards = (int) (($cardCountStmt->fetch()['total_cards'] ?? 0));
            if ($totalCards >= 3) {
                Response::json(['success' => false, 'error' => 'The card limit has already been reached for this wallet.'], 422);
                return;
            }
        }

        $amountInr = $purpose === 'WALLET_ACTIVATION' ? self::WALLET_ACTIVATION_FEE_INR : self::CARD_ISSUANCE_FEE_INR;
        $amountPaise = (int) round($amountInr * 100);
        $paymentOrderId = $this->uuid();
        $receipt = 'eq_' . strtolower($purpose) . '_' . substr(str_replace('-', '', $paymentOrderId), 0, 18);

        $razorpayOrder = $this->razorpayApiRequest('POST', '/orders', [
            'amount' => $amountPaise,
            'currency' => 'INR',
            'receipt' => $receipt,
            'notes' => [
                'payment_order_id' => $paymentOrderId,
                'purpose' => $purpose,
                'citizen_email' => $profile['email'],
            ],
        ]);

        $db->prepare(
            "INSERT INTO payment_orders (
                payment_order_id, user_id, payment_purpose, amount_inr, currency, payment_status,
                fulfillment_status, gateway_order_id, gateway_status, receipt, metadata_json
            ) VALUES (?, ?, ?, ?, ?, 'PENDING', 'PENDING', ?, ?, ?, ?)"
        )->execute([
            $paymentOrderId,
            $userId,
            $purpose,
            $amountInr,
            'INR',
            $razorpayOrder['id'],
            $razorpayOrder['status'] ?? 'created',
            $receipt,
            json_encode($metadata, JSON_UNESCAPED_SLASHES),
        ]);

        $this->auditPaymentEvent($paymentOrderId, 'ORDER_CREATED', $razorpayOrder);

        Response::json([
            'success' => true,
            'message' => $purpose === 'WALLET_ACTIVATION'
                ? 'Wallet activation payment order created successfully.'
                : 'Card issuance payment order created successfully.',
            'payment_order' => [
                'payment_order_id' => $paymentOrderId,
                'purpose' => $purpose,
                'amount_inr' => $amountInr,
                'amount_paise' => $amountPaise,
                'gateway_order_id' => $razorpayOrder['id'],
                'receipt' => $receipt,
            ],
            'checkout' => [
                'key' => $this->razorpayCredentials()['key_id'],
                'amount' => $amountPaise,
                'currency' => 'INR',
                'name' => 'Equinox Beyond Money',
                'description' => $purpose === 'WALLET_ACTIVATION'
                    ? 'Wallet activation fee for 500 Eq onboarding balance'
                    : 'Virtual card issuance fee for a new Equinox wallet card',
                'order_id' => $razorpayOrder['id'],
                'prefill' => [
                    'name' => $profile['full_name'],
                    'email' => $profile['email'],
                    'contact' => $profile['phone_number'] ?? '',
                ],
                'notes' => [
                    'purpose' => $purpose,
                    'citizen' => $profile['full_name'],
                ],
                'theme' => ['color' => '#8a2be2'],
            ],
        ]);
    }

    private function verifyPayment(array $input): void
    {
        $db = $this->db();
        $auth = $this->requireUser();
        $userId = (string) $auth['sub'];
        $paymentOrderId = trim((string) ($input['payment_order_id'] ?? ''));
        $gatewayOrderId = trim((string) ($input['razorpay_order_id'] ?? ''));
        $gatewayPaymentId = trim((string) ($input['razorpay_payment_id'] ?? ''));
        $gatewaySignature = trim((string) ($input['razorpay_signature'] ?? ''));

        if (!$this->isUuid($paymentOrderId) || $gatewayOrderId === '' || $gatewayPaymentId === '' || $gatewaySignature === '') {
            Response::json(['success' => false, 'error' => 'Payment verification data is incomplete.'], 422);
            return;
        }

        $paymentStmt = $db->prepare('SELECT * FROM payment_orders WHERE payment_order_id = ? AND user_id = ?');
        $paymentStmt->execute([$paymentOrderId, $userId]);
        $paymentOrder = $paymentStmt->fetch();

        if (!$paymentOrder) {
            Response::json(['success' => false, 'error' => 'Payment order not found.'], 404);
            return;
        }

        if ((string) $paymentOrder['gateway_order_id'] !== $gatewayOrderId) {
            $this->markPaymentOrderFailure($paymentOrderId, 'Gateway order mismatch during verification.');
            Response::json(['success' => false, 'error' => 'Gateway order mismatch detected.'], 422);
            return;
        }

        $expectedSignature = hash_hmac(
            'sha256',
            $gatewayOrderId . '|' . $gatewayPaymentId,
            $this->razorpayCredentials()['key_secret']
        );

        if (!hash_equals($expectedSignature, $gatewaySignature)) {
            $this->markPaymentOrderFailure($paymentOrderId, 'Invalid Razorpay payment signature.');
            $this->auditPaymentEvent($paymentOrderId, 'SIGNATURE_FAILED', [
                'razorpay_order_id' => $gatewayOrderId,
                'razorpay_payment_id' => $gatewayPaymentId,
            ]);
            Response::json(['success' => false, 'error' => 'Payment signature verification failed.'], 422);
            return;
        }

        if ((string) $paymentOrder['payment_status'] === 'PAID' && (string) $paymentOrder['fulfillment_status'] === 'COMPLETED') {
            Response::json($this->buildExistingPaymentCompletionResponse($paymentOrder));
            return;
        }

        $remotePayment = $this->razorpayApiRequest('GET', '/payments/' . rawurlencode($gatewayPaymentId));
        $gatewayStatus = strtolower((string) ($remotePayment['status'] ?? ''));

        if (!in_array($gatewayStatus, ['authorized', 'captured'], true)) {
            $this->markPaymentOrderFailure($paymentOrderId, 'Payment status from Razorpay was ' . $gatewayStatus . '.');
            $this->auditPaymentEvent($paymentOrderId, 'PAYMENT_STATUS_INVALID', $remotePayment);
            Response::json(['success' => false, 'error' => 'Razorpay has not confirmed a successful payment yet.'], 422);
            return;
        }

        $db->prepare(
            "UPDATE payment_orders
             SET payment_status = 'PAID',
                 gateway_payment_id = ?,
                 gateway_signature = ?,
                 gateway_status = ?,
                 failure_reason = NULL,
                 paid_at = COALESCE(paid_at, CURRENT_TIMESTAMP),
                 updated_at = CURRENT_TIMESTAMP
             WHERE payment_order_id = ?"
        )->execute([
            $gatewayPaymentId,
            $gatewaySignature,
            $gatewayStatus,
            $paymentOrderId,
        ]);

        $this->auditPaymentEvent($paymentOrderId, 'PAYMENT_VERIFIED', $remotePayment);

        $freshStmt = $db->prepare('SELECT * FROM payment_orders WHERE payment_order_id = ?');
        $freshStmt->execute([$paymentOrderId]);
        $freshOrder = $freshStmt->fetch() ?: $paymentOrder;

        try {
            $response = $this->fulfillPaymentOrder($freshOrder, $input);
        } catch (Throwable $exception) {
            $this->markPaymentOrderFulfillmentFailed($paymentOrderId, $exception->getMessage());
            $this->auditPaymentEvent($paymentOrderId, 'FULFILLMENT_FAILED', [
                'message' => $exception->getMessage(),
            ]);
            Response::json(['success' => false, 'error' => $exception->getMessage()], 500);
            return;
        }

        Response::json($response);
    }

    private function transferEq(array $input): void
    {
        $auth = $this->requireUser();
        $senderWallet = $this->findWalletForUser((string) $auth['sub']);
        $receiverWallet = trim((string) ($input['receiver_wallet'] ?? ''));
        $amount = (float) ($input['amount'] ?? 0);
        $note = trim((string) ($input['note'] ?? ''));
        $cardId = trim((string) ($input['card_id'] ?? ''));

        if (!$this->isUuid($receiverWallet)) {
            Response::json(['success' => false, 'error' => 'Please enter a valid receiver wallet ID.'], 422);
            return;
        }

        if ($amount <= 0) {
            Response::json(['success' => false, 'error' => 'Please enter a valid transfer amount.'], 422);
            return;
        }

        if ($cardId !== '') {
            try {
                $card = $this->validateTransferCard((string) $auth['sub'], $cardId);
                $note = trim($note . ' | Paid using ' . ($card['masked_card_number'] ?? ''));
            } catch (RuntimeException $exception) {
                Response::json(['success' => false, 'error' => $exception->getMessage()], 422);
                return;
            }
        }

        $result = $this->callProcedure('CALL transfer_eq(?, ?, ?, ?, ?, ?)', [
            $senderWallet['wallet_id'],
            $receiverWallet,
            $amount,
            $note ?: null,
            $input['latitude'] ?? null,
            $input['longitude'] ?? null,
        ]);

        Response::json($result ?: ['success' => false, 'error' => 'Transfer failed.']);
    }

    private function toggleCardFreeze(array $input): void
    {
        $db = $this->db();
        $auth = $this->requireUser();
        $cardId = trim((string) ($input['card_id'] ?? ''));

        if (!$this->isUuid($cardId)) {
            Response::json(['success' => false, 'error' => 'Invalid card selected.'], 422);
            return;
        }

        $stmt = $db->prepare(
            'SELECT c.card_id, c.card_number, c.is_frozen
             FROM equinox_cards c
             JOIN wallets_equinox w ON w.wallet_id = c.wallet_id
             WHERE c.card_id = ? AND w.user_id = ?'
        );
        $stmt->execute([$cardId, $auth['sub']]);
        $card = $stmt->fetch();

        if (!$card) {
            Response::json(['success' => false, 'error' => 'Card not found.'], 404);
            return;
        }

        $newState = array_key_exists('is_frozen', $input)
            ? ($this->toBool($input['is_frozen']) ? 1 : 0)
            : ((int) $card['is_frozen'] === 1 ? 0 : 1);

        $db->prepare('UPDATE equinox_cards SET is_frozen = ? WHERE card_id = ?')->execute([$newState, $cardId]);

        Response::json([
            'success' => true,
            'message' => $newState === 1 ? 'Card has been frozen successfully.' : 'Card has been unfrozen successfully.',
            'card' => [
                'card_id' => $cardId,
                'is_frozen' => $newState === 1,
                'masked_card_number' => $this->maskCardNumber((string) $card['card_number']),
            ],
        ]);
    }

    private function createPool(array $input): void
    {
        $db = $this->db();
        $auth = $this->requireUser();
        $title = trim((string) ($input['title'] ?? ''));
        $description = trim((string) ($input['description'] ?? ''));
        $targetAmount = (float) ($input['target_amount'] ?? 0);
        $deadline = trim((string) ($input['deadline'] ?? ''));

        if ($title === '' || $targetAmount <= 0 || $deadline === '') {
            Response::json(['success' => false, 'error' => 'Please provide a title, target amount, and deadline.'], 422);
            return;
        }

        $db->prepare(
            'INSERT INTO community_pools (
                pool_id, creator_id, title, description, target_amount, deadline, verified
            ) VALUES (?, ?, ?, ?, ?, ?, 1)'
        )->execute([
            $this->uuid(),
            $auth['sub'],
            $title,
            $description ?: null,
            $targetAmount,
            $deadline,
        ]);

        Response::json(['success' => true, 'message' => 'Community pool has been created successfully.']);
    }

    private function contributePool(array $input): void
    {
        $auth = $this->requireUser();
        $wallet = $this->findWalletForUser((string) $auth['sub']);
        $poolId = trim((string) ($input['pool_id'] ?? ''));
        $amount = (float) ($input['amount'] ?? 0);

        if (!$this->isUuid($poolId) || $amount <= 0) {
            Response::json(['success' => false, 'error' => 'Please enter a valid pool and contribution amount.'], 422);
            return;
        }

        $result = $this->callProcedure('CALL contribute_pool(?, ?, ?)', [
            $wallet['wallet_id'],
            $poolId,
            $amount,
        ]);

        Response::json($result ?: ['success' => false, 'error' => 'Contribution failed.']);
    }

    private function createSkill(array $input): void
    {
        $db = $this->db();
        $auth = $this->requireUser();
        $skillName = trim((string) ($input['skill_name'] ?? ''));
        $description = trim((string) ($input['description'] ?? ''));
        $ratePerHour = (int) ($input['rate_per_hour'] ?? 0);

        if ($skillName === '' || $ratePerHour <= 0) {
            Response::json(['success' => false, 'error' => 'Please provide the skill name and a valid hourly rate.'], 422);
            return;
        }

        $db->prepare(
            'INSERT INTO skill_listings (skill_id, user_id, skill_name, description, rate_per_hour)
             VALUES (?, ?, ?, ?, ?)'
        )->execute([
            $this->uuid(),
            $auth['sub'],
            $skillName,
            $description ?: null,
            $ratePerHour,
        ]);

        Response::json(['success' => true, 'message' => 'Skill listing published successfully.']);
    }

    private function createContract(array $input): void
    {
        $db = $this->db();
        $auth = $this->requireUser();
        $skillId = trim((string) ($input['skill_id'] ?? ''));
        $hours = (int) ($input['hours'] ?? 0);

        if (!$this->isUuid($skillId) || $hours <= 0) {
            Response::json(['success' => false, 'error' => 'Please select a valid skill and number of hours.'], 422);
            return;
        }

        $skillStmt = $db->prepare('SELECT user_id, rate_per_hour FROM skill_listings WHERE skill_id = ? AND is_active = 1');
        $skillStmt->execute([$skillId]);
        $skill = $skillStmt->fetch();

        if (!$skill) {
            Response::json(['success' => false, 'error' => 'Skill listing not found.'], 404);
            return;
        }

        if ((string) $skill['user_id'] === (string) $auth['sub']) {
            Response::json(['success' => false, 'error' => 'You cannot create a contract with your own listing.'], 422);
            return;
        }

        $totalEq = $hours * (int) $skill['rate_per_hour'];

        $db->prepare(
            'INSERT INTO service_contracts (contract_id, provider_id, consumer_id, skill_id, hours, total_eq)
             VALUES (?, ?, ?, ?, ?, ?)'
        )->execute([
            $this->uuid(),
            $skill['user_id'],
            $auth['sub'],
            $skillId,
            $hours,
            $totalEq,
        ]);

        Response::json([
            'success' => true,
            'message' => 'Contract request created successfully.',
            'total_eq' => $totalEq,
        ]);
    }

    private function settleContract(array $input): void
    {
        $db = $this->db();
        $auth = $this->requireUser();
        $contractId = trim((string) ($input['contract_id'] ?? ''));

        if (!$this->isUuid($contractId)) {
            Response::json(['success' => false, 'error' => 'Invalid contract selected.'], 422);
            return;
        }

        $contractStmt = $db->prepare(
            'SELECT contract_id, provider_id, consumer_id, status
             FROM service_contracts
             WHERE contract_id = ?'
        );
        $contractStmt->execute([$contractId]);
        $contract = $contractStmt->fetch();

        if (!$contract) {
            Response::json(['success' => false, 'error' => 'Contract not found.'], 404);
            return;
        }

        if ((string) $contract['provider_id'] !== (string) $auth['sub'] && (string) $contract['consumer_id'] !== (string) $auth['sub']) {
            Response::json(['success' => false, 'error' => 'You are not permitted to settle this contract.'], 403);
            return;
        }

        $consumerWallet = $this->findWalletForUser((string) $contract['consumer_id']);
        $providerWallet = $this->findWalletForUser((string) $contract['provider_id']);

        $result = $this->callProcedure('CALL settle_contract(?, ?, ?)', [
            $contractId,
            $consumerWallet['wallet_id'],
            $providerWallet['wallet_id'],
        ]);

        Response::json($result ?: ['success' => false, 'error' => 'Contract settlement failed.']);
    }

    private function createReview(array $input): void
    {
        $db = $this->db();
        $auth = $this->requireUser();
        $contractId = trim((string) ($input['contract_id'] ?? ''));
        $subjectId = trim((string) ($input['subject_id'] ?? ''));
        $rating = (int) ($input['rating'] ?? 0);
        $comment = trim((string) ($input['comment'] ?? ''));

        if ($rating < 1 || $rating > 5) {
            Response::json(['success' => false, 'error' => 'Rating must be between 1 and 5.'], 422);
            return;
        }

        if ($contractId !== '') {
            $contractStmt = $db->prepare(
                'SELECT contract_id, provider_id, consumer_id, status
                 FROM service_contracts
                 WHERE contract_id = ?'
            );
            $contractStmt->execute([$contractId]);
            $contract = $contractStmt->fetch();

            if (!$contract) {
                Response::json(['success' => false, 'error' => 'Contract not found.'], 404);
                return;
            }

            if ((string) $contract['provider_id'] !== (string) $auth['sub'] && (string) $contract['consumer_id'] !== (string) $auth['sub']) {
                Response::json(['success' => false, 'error' => 'You cannot review this contract.'], 403);
                return;
            }

            if ((string) $contract['status'] !== 'COMPLETED') {
                Response::json(['success' => false, 'error' => 'Reviews can be submitted only after the contract is completed.'], 422);
                return;
            }

            if ($subjectId === '') {
                $subjectId = (string) $contract['provider_id'] === (string) $auth['sub']
                    ? (string) $contract['consumer_id']
                    : (string) $contract['provider_id'];
            }
        }

        if (!$this->isUuid($subjectId) || $subjectId === (string) $auth['sub']) {
            Response::json(['success' => false, 'error' => 'Please choose a valid citizen to review.'], 422);
            return;
        }

        $db->prepare(
            'INSERT INTO user_reviews (review_id, reviewer_id, subject_id, contract_id, rating, comment)
             VALUES (?, ?, ?, ?, ?, ?)'
        )->execute([
            $this->uuid(),
            $auth['sub'],
            $subjectId,
            $contractId ?: null,
            $rating,
            $comment ?: null,
        ]);

        Response::json(['success' => true, 'message' => 'Review submitted successfully.']);
    }

    private function queryDemonstrator(array $input): void
    {
        $db = $this->db();
        $auth = $this->requireUser();
        $preset = trim((string) ($input['preset'] ?? ''));
        $sql = trim((string) ($input['sql'] ?? ''));
        $params = [];

        try {
            if ($preset !== '') {
                [$sql, $params] = $this->presetQuery($preset, (string) $auth['sub']);
            } elseif ($sql !== '') {
                $sql = $this->sanitizeReadOnlySql($sql);
            } else {
                Response::json(['success' => false, 'error' => 'Choose a preset or enter a read-only query.'], 422);
                return;
            }

            $stmt = $db->prepare($sql);
            $stmt->execute($params);
            $rows = $stmt->fetchAll();
            $columns = $this->queryColumns($stmt, $rows);
        } catch (Throwable $exception) {
            Response::json(['success' => false, 'error' => $exception->getMessage()], 422);
            return;
        }

        Response::json([
            'success' => true,
            'query' => $sql,
            'columns' => $columns,
            'rows' => $rows,
            'row_count' => count($rows),
            'executed_at' => date(DATE_ATOM),
        ]);
    }

    private function stream(): void
    {
        $auth = $this->requireUser();
        $wallet = $this->findWalletForUser((string) $auth['sub']);

        Response::json($this->fetchLiveState((string) $auth['sub'], (string) $wallet['wallet_id']));
    }

    private function fulfillPaymentOrder(array $paymentOrder, array $input): array
    {
        $db = $this->db();
        $paymentOrderId = (string) $paymentOrder['payment_order_id'];
        $metadata = $this->decodeJsonArray($paymentOrder['metadata_json'] ?? null);

        if ((string) $paymentOrder['fulfillment_status'] === 'COMPLETED') {
            return $this->buildExistingPaymentCompletionResponse($paymentOrder);
        }

        if ((string) $paymentOrder['payment_purpose'] === 'WALLET_ACTIVATION') {
            $latitude = $input['latitude'] ?? ($metadata['latitude'] ?? null);
            $longitude = $input['longitude'] ?? ($metadata['longitude'] ?? null);

            $activation = $this->callProcedure('CALL activate_wallet(?, ?, ?)', [
                $paymentOrder['user_id'],
                $latitude,
                $longitude,
            ]);

            if (!($activation['success'] ?? false)) {
                throw new RuntimeException((string) ($activation['error'] ?? 'Wallet activation failed after payment verification.'));
            }

            $db->prepare(
                "UPDATE payment_orders
                 SET fulfillment_status = 'COMPLETED',
                     resource_reference = ?,
                     fulfilled_at = CURRENT_TIMESTAMP,
                     updated_at = CURRENT_TIMESTAMP
                 WHERE payment_order_id = ?"
            )->execute([
                $activation['wallet_id'] ?? null,
                $paymentOrderId,
            ]);

            $this->auditPaymentEvent($paymentOrderId, 'FULFILLED_WALLET_ACTIVATION', $activation);

            return [
                'success' => true,
                'message' => 'Payment verified successfully. Your wallet is now active and 500 Eq has been credited.',
                'payment' => $this->fetchPaymentOrderDisplay($paymentOrderId),
                'wallet' => $activation,
            ];
        }

        if ((string) $paymentOrder['payment_purpose'] === 'CARD_ISSUANCE') {
            $cardName = trim((string) ($metadata['card_name'] ?? 'Primary'));
            $card = $this->createVirtualCardRecord((string) $paymentOrder['user_id'], $cardName, $paymentOrderId);

            $db->prepare(
                "UPDATE payment_orders
                 SET fulfillment_status = 'COMPLETED',
                     resource_reference = ?,
                     fulfilled_at = CURRENT_TIMESTAMP,
                     updated_at = CURRENT_TIMESTAMP
                 WHERE payment_order_id = ?"
            )->execute([
                $card['card_id'],
                $paymentOrderId,
            ]);

            $this->auditPaymentEvent($paymentOrderId, 'FULFILLED_CARD_ISSUANCE', [
                'card_id' => $card['card_id'],
                'card_number' => $card['masked_card_number'],
                'card_name' => $card['card_name'],
            ]);

            return [
                'success' => true,
                'message' => 'Payment verified successfully. Your new virtual card has been issued.',
                'payment' => $this->fetchPaymentOrderDisplay($paymentOrderId),
                'card' => $card,
            ];
        }

        throw new RuntimeException('Unsupported payment purpose.');
    }

    private function buildExistingPaymentCompletionResponse(array $paymentOrder): array
    {
        $purpose = (string) $paymentOrder['payment_purpose'];
        $response = [
            'success' => true,
            'message' => $purpose === 'WALLET_ACTIVATION'
                ? 'This wallet activation payment has already been completed.'
                : 'This card issuance payment has already been completed.',
            'payment' => $this->mapPaymentOrderForDisplay($paymentOrder),
        ];

        if ($purpose === 'CARD_ISSUANCE' && !empty($paymentOrder['resource_reference']) && $this->isUuid((string) $paymentOrder['resource_reference'])) {
            $card = $this->fetchCardById((string) $paymentOrder['resource_reference']);
            if ($card) {
                $response['card'] = $card;
            }
        }

        if ($purpose === 'WALLET_ACTIVATION') {
            $wallet = $this->findWalletForUser((string) $paymentOrder['user_id']);
            $response['wallet'] = [
                'wallet_id' => $wallet['wallet_id'],
                'balance' => $wallet['balance'],
                'status' => $wallet['status'],
            ];
        }

        return $response;
    }

    private function createVirtualCardRecord(string $userId, string $cardName, string $paymentOrderId): array
    {
        $db = $this->db();
        $wallet = $this->findWalletForUser($userId);

        if ((string) $wallet['status'] !== 'ACTIVE') {
            throw new RuntimeException('Activate the wallet before issuing a card.');
        }

        $cardCountStmt = $db->prepare('SELECT COUNT(*) AS total_cards FROM equinox_cards WHERE wallet_id = ?');
        $cardCountStmt->execute([$wallet['wallet_id']]);
        $totalCards = (int) (($cardCountStmt->fetch()['total_cards'] ?? 0));
        if ($totalCards >= 3) {
            throw new RuntimeException('The maximum number of cards has already been reached for this wallet.');
        }

        $cardId = $this->uuid();
        $cardNumber = $this->formatCardNumber($this->generateUniqueCardNumber());
        $cvv = (string) random_int(100, 999);
        $expiryDate = date('m/y', strtotime('+4 years'));

        $db->prepare(
            'INSERT INTO equinox_cards (
                card_id, wallet_id, issued_via_payment_order_id, card_number, cvv_hash, expiry_date, card_name
            ) VALUES (?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $cardId,
            $wallet['wallet_id'],
            $paymentOrderId,
            $cardNumber,
            password_hash($cvv, PASSWORD_DEFAULT),
            $expiryDate,
            mb_substr($cardName !== '' ? $cardName : 'Primary', 0, 30),
        ]);

        return [
            'card_id' => $cardId,
            'card_name' => mb_substr($cardName !== '' ? $cardName : 'Primary', 0, 30),
            'card_number' => $cardNumber,
            'masked_card_number' => $this->maskCardNumber($cardNumber),
            'expiry_date' => $expiryDate,
            'cvv' => $cvv,
            'is_frozen' => false,
        ];
    }

    private function fetchPaymentOrderDisplay(string $paymentOrderId): array
    {
        $stmt = $this->db()->prepare('SELECT * FROM payment_orders WHERE payment_order_id = ?');
        $stmt->execute([$paymentOrderId]);
        $payment = $stmt->fetch();
        if (!$payment) {
            throw new RuntimeException('Payment order not found after update.');
        }

        return $this->mapPaymentOrderForDisplay($payment);
    }

    private function fetchCardById(string $cardId): ?array
    {
        $stmt = $this->db()->prepare(
            'SELECT card_id, wallet_id, issued_via_payment_order_id, card_number, expiry_date, card_name, is_frozen, created_at
             FROM equinox_cards
             WHERE card_id = ?'
        );
        $stmt->execute([$cardId]);
        $card = $stmt->fetch();

        return $card ? $this->mapCardForDisplay($card) : null;
    }

    private function fetchLiveState(string $userId, string $walletId): array
    {
        $db = $this->db();
        $walletStmt = $db->prepare(
            'SELECT balance, status, updated_at
             FROM wallets_equinox
             WHERE wallet_id = ?'
        );
        $walletStmt->execute([$walletId]);

        $notificationStmt = $db->prepare(
            'SELECT COUNT(*) AS unread_count, MAX(created_at) AS latest_notification
             FROM notifications
             WHERE user_id = ? AND is_read = 0'
        );
        $notificationStmt->execute([$userId]);

        $paymentStmt = $db->prepare(
            'SELECT COUNT(*) AS total_payment_orders, MAX(created_at) AS latest_payment
             FROM payment_orders
             WHERE user_id = ?'
        );
        $paymentStmt->execute([$userId]);

        return [
            'success' => true,
            'wallet' => $walletStmt->fetch(),
            'notifications' => $notificationStmt->fetch(),
            'payments' => $paymentStmt->fetch(),
            'server_time' => date(DATE_ATOM),
        ];
    }

    private function callProcedure(string $sql, array $params): array
    {
        $stmt = $this->db()->prepare($sql);
        $stmt->execute($params);
        $result = $stmt->fetch() ?: [];
        while ($stmt->nextRowset()) {
        }
        $stmt->closeCursor();
        return $result;
    }

    private function validateTransferCard(string $userId, string $cardId): array
    {
        $db = $this->db();
        $stmt = $db->prepare(
            'SELECT c.card_id, c.card_number, c.is_frozen
             FROM equinox_cards c
             JOIN wallets_equinox w ON w.wallet_id = c.wallet_id
             WHERE c.card_id = ? AND w.user_id = ?'
        );
        $stmt->execute([$cardId, $userId]);
        $card = $stmt->fetch();

        if (!$card) {
            throw new RuntimeException('Selected card was not found.');
        }

        if ((int) $card['is_frozen'] === 1) {
            throw new RuntimeException('The selected card is currently frozen.');
        }

        return [
            'card_id' => $card['card_id'],
            'masked_card_number' => $this->maskCardNumber((string) $card['card_number']),
        ];
    }

    private function recordLogin(string $userId): void
    {
        $this->db()->prepare(
            'INSERT INTO login_history (log_id, user_id, device_fingerprint, ip_address, login_status)
             VALUES (?, ?, ?, ?, ?)'
        )->execute([
            $this->uuid(),
            $userId,
            mb_substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? 'Unknown device'), 0, 255),
            mb_substr((string) ($_SERVER['REMOTE_ADDR'] ?? '127.0.0.1'), 0, 45),
            'SUCCESS',
        ]);
    }

    private function requireUser(): array
    {
        $user = Auth::user();
        if (!$user) {
            Response::json(['success' => false, 'error' => 'Unauthorized.'], 401);
            exit;
        }

        return $user;
    }

    private function input(): array
    {
        $raw = file_get_contents('php://input') ?: '';
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : [];
    }

    private function serveFile(string $path, string $contentType): void
    {
        header('Content-Type: ' . $contentType);
        header('Access-Control-Allow-Origin: *');
        // Disable sensor access for third-party scripts (Razorpay biometrics)
        header('Permissions-Policy: accelerometer=(), gyroscope=(), magnetometer=(), camera=(), microphone=()');
        readfile($path);
    }

    private function queryColumns(PDOStatement $stmt, array $rows): array
    {
        if (!empty($rows)) {
            return array_keys($rows[0]);
        }

        $columns = [];
        for ($index = 0; $index < $stmt->columnCount(); $index++) {
            $meta = $stmt->getColumnMeta($index);
            if (is_array($meta) && isset($meta['name'])) {
                $columns[] = $meta['name'];
            }
        }

        return $columns;
    }

    private function presetQuery(string $preset, string $userId): array
    {
        $wallet = $this->findWalletForUser($userId);

        $map = [
            'wallet_and_trust_join' => [
                'sql' => 'SELECT
                    u.full_name,
                    u.email,
                    u.trust_score,
                    u.kyc_status,
                    w.wallet_id,
                    w.balance,
                    w.status AS wallet_status,
                    ts.total_sent,
                    ts.total_received,
                    ts.completed_contracts
                 FROM users_profile u
                 INNER JOIN wallets_equinox w ON w.user_id = u.user_id
                 LEFT JOIN trust_scores ts ON ts.user_id = u.user_id
                 WHERE u.user_id = ?',
                'params' => [$userId],
            ],
            'ledger_multi_join' => [
                'sql' => 'SELECT
                    t.transaction_id,
                    t.transaction_type,
                    t.amount,
                    t.status,
                    t.note,
                    t.created_at,
                    sender.full_name AS sender_name,
                    receiver.full_name AS receiver_name,
                    sw.status AS sender_wallet_status,
                    rw.status AS receiver_wallet_status
                 FROM transactions_master t
                 LEFT JOIN wallets_equinox sw ON sw.wallet_id = t.sender_wallet
                 LEFT JOIN users_profile sender ON sender.user_id = sw.user_id
                 LEFT JOIN wallets_equinox rw ON rw.wallet_id = t.receiver_wallet
                 LEFT JOIN users_profile receiver ON receiver.user_id = rw.user_id
                 ORDER BY t.created_at DESC
                 LIMIT 20',
                'params' => [],
            ],
            'payments_cards_join' => [
                'sql' => 'SELECT
                    p.payment_order_id,
                    p.payment_purpose,
                    p.amount_inr,
                    p.payment_status,
                    p.fulfillment_status,
                    p.gateway_status,
                    c.card_name,
                    c.card_number,
                    c.is_frozen,
                    p.created_at,
                    p.fulfilled_at
                 FROM payment_orders p
                 LEFT JOIN equinox_cards c ON c.issued_via_payment_order_id = p.payment_order_id
                 WHERE p.user_id = ?
                 ORDER BY p.created_at DESC
                 LIMIT 20',
                'params' => [$userId],
            ],
            'contracts_marketplace_join' => [
                'sql' => 'SELECT
                    c.contract_id,
                    c.status AS contract_status,
                    c.hours,
                    c.total_eq,
                    s.skill_name,
                    s.rate_per_hour,
                    provider.full_name AS provider_name,
                    consumer.full_name AS consumer_name,
                    c.created_at
                 FROM service_contracts c
                 INNER JOIN skill_listings s ON s.skill_id = c.skill_id
                 INNER JOIN users_profile provider ON provider.user_id = c.provider_id
                 INNER JOIN users_profile consumer ON consumer.user_id = c.consumer_id
                 ORDER BY c.created_at DESC
                 LIMIT 20',
                'params' => [],
            ],
            'pools_group_join' => [
                'sql' => 'SELECT
                    p.title,
                    creator.full_name AS creator_name,
                    p.target_amount,
                    p.raised_amount,
                    COUNT(pc.contrib_id) AS contribution_count,
                    COALESCE(SUM(pc.amount), 0) AS contributed_eq,
                    p.status,
                    p.deadline
                 FROM community_pools p
                 INNER JOIN users_profile creator ON creator.user_id = p.creator_id
                 LEFT JOIN pool_contributions pc ON pc.pool_id = p.pool_id
                 GROUP BY
                    p.pool_id, p.title, creator.full_name, p.target_amount, p.raised_amount, p.status, p.deadline
                 ORDER BY p.created_at DESC
                 LIMIT 20',
                'params' => [],
            ],
            'wallet_card_inventory' => [
                'sql' => 'SELECT
                    c.card_name,
                    c.card_number,
                    c.expiry_date,
                    c.is_frozen,
                    p.payment_purpose,
                    p.payment_status,
                    p.fulfillment_status,
                    p.paid_at
                 FROM equinox_cards c
                 LEFT JOIN payment_orders p ON p.payment_order_id = c.issued_via_payment_order_id
                 WHERE c.wallet_id = ?
                 ORDER BY c.created_at DESC',
                'params' => [$wallet['wallet_id']],
            ],
        ];

        if (!isset($map[$preset])) {
            throw new RuntimeException('Unknown query preset selected.');
        }

        return [$map[$preset]['sql'], $map[$preset]['params']];
    }

    private function sanitizeReadOnlySql(string $sql): string
    {
        $sql = trim($sql);
        $sql = preg_replace('/;\s*$/', '', $sql) ?? $sql;

        if (strpos($sql, ';') !== false) {
            throw new RuntimeException('Only one read-only SQL statement can be executed at a time.');
        }

        if (!preg_match('/^\s*(select|show|describe|desc|explain)\b/i', $sql)) {
            throw new RuntimeException('Only SELECT, SHOW, DESCRIBE, DESC, or EXPLAIN statements are allowed.');
        }

        if (preg_match('/\b(insert|update|delete|drop|alter|truncate|create|replace|grant|revoke|call|commit|rollback)\b/i', $sql)) {
            throw new RuntimeException('Write operations are blocked in the SQL demonstrator.');
        }

        if (preg_match('/^\s*select\b/i', $sql) && !preg_match('/\blimit\b/i', $sql)) {
            $sql .= ' LIMIT 50';
        }

        return $sql;
    }

    private function mapCardForDisplay(array $card): array
    {
        $cardNumber = (string) $card['card_number'];

        return [
            'card_id' => $card['card_id'],
            'card_name' => $card['card_name'],
            'card_number' => $cardNumber,
            'masked_card_number' => $this->maskCardNumber($cardNumber),
            'expiry_date' => $card['expiry_date'],
            'is_frozen' => (int) $card['is_frozen'] === 1,
            'created_at' => $card['created_at'],
            'brand' => str_starts_with(str_replace(' ', '', $cardNumber), '4') ? 'VISA' : 'EQUINOX',
            'issued_via_payment_order_id' => $card['issued_via_payment_order_id'] ?? null,
        ];
    }

    private function mapPaymentOrderForDisplay(array $payment): array
    {
        $metadata = $this->decodeJsonArray($payment['metadata_json'] ?? null);

        return [
            'payment_order_id' => $payment['payment_order_id'],
            'payment_purpose' => $payment['payment_purpose'],
            'amount_inr' => (float) $payment['amount_inr'],
            'currency' => $payment['currency'],
            'payment_status' => $payment['payment_status'],
            'fulfillment_status' => $payment['fulfillment_status'],
            'gateway_order_id' => $payment['gateway_order_id'],
            'gateway_payment_id' => $payment['gateway_payment_id'],
            'gateway_status' => $payment['gateway_status'],
            'receipt' => $payment['receipt'],
            'resource_reference' => $payment['resource_reference'],
            'failure_reason' => $payment['failure_reason'],
            'paid_at' => $payment['paid_at'],
            'fulfilled_at' => $payment['fulfilled_at'],
            'created_at' => $payment['created_at'],
            'updated_at' => $payment['updated_at'],
            'metadata' => $metadata,
            'title' => $payment['payment_purpose'] === 'WALLET_ACTIVATION'
                ? 'Wallet Activation Fee'
                : 'Virtual Card Issuance Fee',
        ];
    }

    private function mapPoolForDisplay(array $pool): array
    {
        $target = (float) $pool['target_amount'];
        $raised = (float) $pool['raised_amount'];
        $progress = $target > 0 ? round(($raised / $target) * 100, 2) : 0;
        $pool['progress_percent'] = min(100, max(0, $progress));
        return $pool;
    }

    private function queryPresetMeta(): array
    {
        return [
            ['id' => 'wallet_and_trust_join', 'label' => 'Wallet + Trust Join', 'description' => 'INNER JOIN wallets with citizens and LEFT JOIN trust scores.'],
            ['id' => 'ledger_multi_join', 'label' => 'Ledger Multi-Join', 'description' => 'Transactions joined with sender and receiver wallets and citizen names.'],
            ['id' => 'payments_cards_join', 'label' => 'Payments + Cards Join', 'description' => 'LEFT JOIN card issuance records with verified Razorpay orders.'],
            ['id' => 'contracts_marketplace_join', 'label' => 'Contracts Marketplace Join', 'description' => 'INNER JOIN service contracts, skills, provider, and consumer data.'],
            ['id' => 'pools_group_join', 'label' => 'Pools Group Join', 'description' => 'JOIN + GROUP BY view of community pools and contribution totals.'],
            ['id' => 'wallet_card_inventory', 'label' => 'Wallet Card Inventory', 'description' => 'Card inventory for the signed-in wallet joined to payment and issuance metadata.'],
        ];
    }

    private function fetchUserProfileForPayment(string $userId): array
    {
        $stmt = $this->db()->prepare(
            'SELECT full_name, email, phone_number
             FROM users_profile
             WHERE user_id = ?'
        );
        $stmt->execute([$userId]);
        $profile = $stmt->fetch();

        if (!$profile) {
            throw new RuntimeException('User profile not found for payment setup.');
        }

        return $profile;
    }

    private function auditPaymentEvent(string $paymentOrderId, string $eventType, array $payload = []): void
    {
        $this->db()->prepare(
            'INSERT INTO payment_audit_logs (audit_id, payment_order_id, event_type, payload_json)
             VALUES (?, ?, ?, ?)'
        )->execute([
            $this->uuid(),
            $paymentOrderId,
            $eventType,
            !empty($payload) ? json_encode($payload, JSON_UNESCAPED_SLASHES) : null,
        ]);
    }

    private function markPaymentOrderFailure(string $paymentOrderId, string $message): void
    {
        $this->db()->prepare(
            "UPDATE payment_orders
             SET payment_status = 'FAILED',
                 failure_reason = ?,
                 updated_at = CURRENT_TIMESTAMP
             WHERE payment_order_id = ?"
        )->execute([$message, $paymentOrderId]);
    }

    private function markPaymentOrderFulfillmentFailed(string $paymentOrderId, string $message): void
    {
        $this->db()->prepare(
            "UPDATE payment_orders
             SET fulfillment_status = 'FAILED',
                 failure_reason = ?,
                 updated_at = CURRENT_TIMESTAMP
             WHERE payment_order_id = ?"
        )->execute([$message, $paymentOrderId]);
    }

    private function razorpayApiRequest(string $method, string $path, ?array $payload = null): array
    {
        $credentials = $this->razorpayCredentials();
        $url = 'https://api.razorpay.com/v1' . $path;
        $ch = curl_init($url);

        if ($ch === false) {
            throw new RuntimeException('Unable to initialise the Razorpay HTTP client.');
        }

        $headers = ['Content-Type: application/json'];

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_USERPWD => $credentials['key_id'] . ':' . $credentials['key_secret'],
            CURLOPT_HTTPAUTH => CURLAUTH_BASIC,
            CURLOPT_CUSTOMREQUEST => strtoupper($method),
            CURLOPT_HTTPHEADER => $headers,
        ]);

        if ($payload !== null && strtoupper($method) !== 'GET') {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload, JSON_UNESCAPED_SLASHES));
        }

        $response = curl_exec($ch);
        if ($response === false) {
            $error = curl_error($ch);
            curl_close($ch);
            throw new RuntimeException('Razorpay request failed: ' . $error);
        }

        $statusCode = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        $decoded = json_decode($response, true);
        if (!is_array($decoded)) {
            throw new RuntimeException('Unexpected Razorpay response received.');
        }

        if ($statusCode >= 400) {
            $message = $decoded['error']['description'] ?? $decoded['error']['reason'] ?? 'Razorpay request failed.';
            throw new RuntimeException((string) $message);
        }

        return $decoded;
    }

    private function isRazorpayConfigured(): bool
    {
        try {
            $credentials = $this->razorpayCredentials();
            return $credentials['key_id'] !== '' && $credentials['key_secret'] !== '';
        } catch (Throwable) {
            return false;
        }
    }

    private function razorpayCredentials(): array
    {
        Env::load(dirname(__DIR__) . '/.env');
        $keyId = trim((string) (Env::get('RAZORPAY_KEY_ID') ?? Env::get('VITE_RAZORPAY_KEY_ID', '')));
        $keySecret = trim((string) (Env::get('RAZORPAY_KEY_SECRET') ?? Env::get('VITE_RAZORPAY_KEY_SECRET', '')));

        if ($keyId === '' || $keySecret === '') {
            throw new RuntimeException('Missing Razorpay credentials in the environment file.');
        }

        return [
            'key_id' => $keyId,
            'key_secret' => $keySecret,
        ];
    }

    private function decodeJsonArray(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }

        if (!is_string($value) || trim($value) === '') {
            return [];
        }

        $decoded = json_decode($value, true);
        return is_array($decoded) ? $decoded : [];
    }

    private function findWalletForUser(string $userId): array
    {
        $stmt = $this->db()->prepare(
            'SELECT wallet_id, balance, status
             FROM wallets_equinox
             WHERE user_id = ?'
        );
        $stmt->execute([$userId]);
        $wallet = $stmt->fetch();

        if (!$wallet) {
            throw new RuntimeException('Wallet not found.');
        }

        return $wallet;
    }

    private function generateUniqueCardNumber(): string
    {
        $db = $this->db();

        do {
            $candidate = $this->generateCardNumber();
            $stmt = $db->prepare('SELECT card_id FROM equinox_cards WHERE card_number = ?');
            $stmt->execute([$this->formatCardNumber($candidate)]);
        } while ($stmt->fetch());

        return $candidate;
    }

    private function generateCardNumber(): string
    {
        $body = '5278';
        for ($index = 0; $index < 11; $index++) {
            $body .= (string) random_int(0, 9);
        }

        return $body . $this->luhnCheckDigit($body);
    }

    private function luhnCheckDigit(string $number): int
    {
        $sum = 0;
        $reversed = strrev($number);

        for ($index = 0, $length = strlen($reversed); $index < $length; $index++) {
            $digit = (int) $reversed[$index];
            if ($index % 2 === 0) {
                $digit *= 2;
                if ($digit > 9) {
                    $digit -= 9;
                }
            }
            $sum += $digit;
        }

        return (10 - ($sum % 10)) % 10;
    }

    private function formatCardNumber(string $number): string
    {
        return trim(chunk_split($number, 4, ' '));
    }

    private function maskCardNumber(string $cardNumber): string
    {
        $digits = preg_replace('/\D+/', '', $cardNumber) ?? $cardNumber;
        return '**** **** **** ' . substr($digits, -4);
    }

    private function isDate(string $value): bool
    {
        return (bool) preg_match('/^\d{4}-\d{2}-\d{2}$/', $value);
    }

    private function isUuid(string $value): bool
    {
        return (bool) preg_match(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i',
            $value
        );
    }

    private function toBool(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        return in_array(strtolower((string) $value), ['1', 'true', 'yes', 'on'], true);
    }

    private function daysSince(string $timestamp): int
    {
        $time = strtotime($timestamp);
        if ($time === false) {
            return 0;
        }

        return max(0, (int) floor((time() - $time) / 86400));
    }

    private function uuid(): string
    {
        $data = random_bytes(16);
        $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
        $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }

    private function db(): PDO
    {
        if (!$this->db instanceof PDO) {
            $this->db = Database::connection();
        }

        return $this->db;
    }

    private function handleUpiPayment(array $input): void
    {
        $db = $this->db();
        $auth = $this->requireUser();
        $userId = (string) $auth['sub'];
        $upiId = trim((string) ($input['upi_id'] ?? ''));
        $amount = (float) ($input['upi_amount'] ?? 0);

        if (!$upiId || !preg_match('/.+@upi$/', $upiId)) {
            Response::json(['success' => false, 'error' => 'Invalid UPI ID format.'], 422);
            return;
        }

        if ($amount <= 0) {
            Response::json(['success' => false, 'error' => 'Amount must be greater than zero.'], 422);
            return;
        }

        // For demo purposes, we'll create a UPI transaction log entry
        try {
            $transactionId = $this->uuid();
            $stmt = $db->prepare(
                'INSERT INTO transactions_master (transaction_id, receiver_wallet, amount, transaction_type, status, note, created_at)
                 SELECT ?, wallet_id, ?, \'P2P\', \'PENDING\', ?, CURRENT_TIMESTAMP
                 FROM wallets_equinox WHERE user_id = ?'
            );

            $stmt->execute([
                $transactionId,
                $amount,
                "UPI Payment initiated to: {$upiId}",
                $userId,
            ]);

            Response::json([
                'success' => true,
                'message' => "UPI payment of Rs. {$amount} initiated to {$upiId}. Complete the payment in your UPI app.",
                'transaction_id' => $transactionId,
                'upi_id' => $upiId,
                'amount' => $amount,
            ]);
        } catch (Throwable $e) {
            Response::json([
                'success' => false,
                'error' => 'Could not process UPI payment. ' . $e->getMessage(),
            ], 500);
        }
    }
}
