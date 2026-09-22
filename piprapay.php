<?php

/**
 * PipraPay FOSSBilling Gateway Module (V3+ Fixed)
 *
 * Endpoint : /checkout/redirect
 * Header   : MHS-PIPRAPAY-API-KEY
 * Fields   : email_address, mobile_number, return_url
 *
 * Copyright (c) 2025 piprapay
 * Website: https://piprapay.com
 */

class Payment_Adapter_piprapay extends Payment_AdapterAbstract implements \FOSSBilling\InjectionAwareInterface
{
    private $config = [];

    protected ?\Pimple\Container $di;

    public function setDi(\Pimple\Container $di): void
    {
        $this->di = $di;
    }

    public function getDi(): ?\Pimple\Container
    {
        return $this->di;
    }

    public function __construct($config)
    {
        $this->config = $config;

        if (empty($this->config['api_key'])) {
            throw new Payment_Exception(
                'The ":pay_gateway" payment gateway is not fully configured. Please configure the :missing',
                [':pay_gateway' => 'PipraPay', ':missing' => 'API KEY']
            );
        }

        if (empty($this->config['api_url'])) {
            throw new Payment_Exception(
                'The ":pay_gateway" payment gateway is not fully configured. Please configure the :missing',
                [':pay_gateway' => 'PipraPay', ':missing' => 'API URL']
            );
        }

        if (empty($this->config['currency'])) {
            $this->config['currency'] = 'BDT';
        }
    }

    public static function getConfig()
    {
        return [
            'supports_one_time_payments' => true,
            'supports_subscriptions'     => false,
            'description'                => 'Accept payments via PipraPay',
            'logo' => [
                'logo'   => 'piprapay/favicon.png',
                'height' => '50px',
                'width'  => '50px',
            ],
            'form' => [
                'api_key' => [
                    'text', [
                        'label'    => 'API Key:',
                        'required' => true,
                    ],
                ],
                'api_url' => [
                    'text', [
                        'label'    => 'API URL (e.g. https://meshcoin24.com):',
                        'required' => true,
                        'value'    => 'https://meshcoin24.com',
                    ],
                ],
                'currency' => [
                    'text', [
                        'label'    => 'Currency (BDT/USD):',
                        'required' => true,
                        'value'    => 'BDT',
                    ],
                ],
            ],
        ];
    }

    public function getHtml($api_admin, $invoice_id, $subscription)
    {
        $invoice    = $api_admin->invoice_get(['id' => $invoice_id]);
        $data       = $this->preparePaymentData($invoice);
        $paymentUrl = $this->createCharge($data);

        return $this->generatePaymentForm($paymentUrl);
    }

    public function processTransaction($api_admin, $id, $data, $gateway_id)
    {
        $ipn = $this->validateIpn($data);

        if (!$ipn) {
            throw new Payment_Exception('Invalid IPN request');
        }

        $pp_id = $ipn['pp_id'] ?? ($data['get']['pp_id'] ?? null);
        if (!$pp_id) {
            throw new Payment_Exception('Missing payment ID in IPN');
        }

        $payment = $this->verifyPayment($pp_id);

        if (($payment['status'] ?? '') !== 'completed') {
            throw new Payment_Exception('Payment not completed. Status: ' . ($payment['status'] ?? 'unknown'));
        }

        $invoice     = $this->di['db']->getExistingModelById('Invoice', $payment['metadata']['invoiceid'], 'Invoice not found');
        $transaction = $this->di['db']->getExistingModelById('Transaction', $id, 'Transaction not found');

        $tx_data = [
            'id'         => $id,
            'invoice_id' => $invoice->id,
            'txn_status' => $payment['status'],
            'txn_id'     => $payment['transaction_id'] ?? $pp_id,
            'amount'     => $payment['amount'],
            'currency'   => $payment['currency'] ?? $this->config['currency'],
            'type'       => $payment['payment_method'] ?? 'piprapay',
            'status'     => 'complete',
        ];

        $transactionService = $this->di['mod_service']('Invoice', 'Transaction');
        $transactionService->update($transaction, $tx_data);

        $bd = [
            'amount'      => $payment['amount'],
            'description' => ($payment['payment_method'] ?? 'PipraPay') . ' Transaction ID: ' . ($payment['transaction_id'] ?? $pp_id),
            'type'        => 'transaction',
            'rel_id'      => $transaction->id,
        ];

        $client        = $this->di['db']->getExistingModelById('Client', $invoice->client_id, 'Client not found');
        $clientService = $this->di['mod_service']('client');
        $clientService->addFunds($client, $bd['amount'], $bd['description'], $bd);

        $invoiceService = $this->di['mod_service']('Invoice');
        $invoiceService->payInvoiceWithCredits($invoice);
        $invoiceService->doBatchPayWithCredits(['client_id' => $invoice->client_id]);

        return true;
    }

    // ─── Private helpers ─────────────────────────────────────────────────────

    private function getBaseUrl(): string
    {
        return rtrim($this->config['api_url'], '/');
    }

    private function preparePaymentData(array $invoice): array
    {
        $client = $invoice['client'];

        $notifyUrl = $this->config['notify_url'] ?? $this->di['tools']->url('invoice/' . $invoice['id']);
        $returnUrl = $this->di['tools']->url('invoice/' . $invoice['id']);

        // www strip — same as WHMCS module to avoid URL whitelist issues
        $notifyUrl = preg_replace('/(https?:\/\/)www\./i', '$1', $notifyUrl);
        $returnUrl = preg_replace('/(https?:\/\/)www\./i', '$1', $returnUrl);

        return [
            'full_name'     => trim($client['first_name'] . ' ' . $client['last_name']),
            'email_address' => !empty($client['email']) ? $client['email'] : 'noreply@example.com',
            'mobile_number' => !empty($client['phone_cc'] . $client['phone']) ? $client['phone_cc'] . $client['phone'] : '01700000000',
            'amount'        => $invoice['total'],
            'currency'      => $this->config['currency'],
            'metadata'      => [
                'invoiceid' => $invoice['id'],
            ],
            'return_url'    => $returnUrl,
            'webhook_url'   => $notifyUrl,
        ];
    }

    private function generatePaymentForm(string $paymentUrl): string
    {
        $form  = '<form action="' . htmlspecialchars($paymentUrl) . '" method="GET" id="payment_form">';
        $form .= '<input class="bb-button bb-button-submit" type="submit" value="Pay with PipraPay" id="payment_button"/>';
        $form .= '</form>';
        $form .= '<script>setTimeout(function(){ document.getElementById("payment_form").submit(); }, 500);</script>';

        return $form;
    }

    private function createCharge(array $data): string
    {
        // V3+ endpoint
        $url      = $this->getBaseUrl() . '/checkout/redirect';
        $response = $this->makeApiRequest($url, $data);

        if (!empty($response['pp_url'])) {
            return $response['pp_url'];
        }

        throw new Payment_Exception('Failed to create payment: ' . json_encode($response));
    }

    private function verifyPayment(string $pp_id): array
    {
        $url      = $this->getBaseUrl() . '/api/verify-payments';
        $response = $this->makeApiRequest($url, ['pp_id' => $pp_id]);

        if (isset($response['status']) && $response['status'] === 'completed') {
            return $response;
        }

        throw new Payment_Exception('Failed to verify payment: ' . json_encode($response));
    }

    private function validateIpn(array $data): array|false
    {
        $rawData = file_get_contents('php://input');
        if (!empty($rawData)) {
            $ipn = json_decode($rawData, true);
            if (json_last_error() === JSON_ERROR_NONE && !empty($ipn)) {
                return $ipn;
            }
        }

        if (!empty($data['get'])) {
            return $data['get'];
        }

        if (!empty($data['post'])) {
            return $data['post'];
        }

        return false;
    }

    private function makeApiRequest(string $url, array $data): array
    {
        $ch = curl_init();

        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($data),
            CURLOPT_HTTPHEADER     => [
                'Accept: application/json',
                'Content-Type: application/json',
                'MHS-PIPRAPAY-API-KEY: ' . $this->config['api_key'],
            ],
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error    = curl_error($ch);
        curl_close($ch);

        if ($error) {
            throw new Payment_Exception('PipraPay cURL error: ' . $error);
        }

        if (empty($response)) {
            throw new Payment_Exception('PipraPay API empty response. HTTP: ' . $httpCode);
        }

        $result = json_decode($response, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new Payment_Exception('PipraPay invalid JSON: ' . substr($response, 0, 300));
        }

        return $result;
    }
}
