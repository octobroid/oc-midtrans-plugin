<?php namespace Octobro\Midtrans\PaymentTypes;

use Input;
use Flash;
use Redirect;
use Exception;
use Veritrans_Snap;
use Veritrans_Config;
use ApplicationException;
use Responsiv\Pay\Classes\GatewayBase;

class Snap extends GatewayBase
{

    /**
     * {@inheritDoc}
     */
    public function gatewayDetails()
    {
        return [
            'name'        => 'Snap',
            'description' => 'Snap payment method from Midtrans.'
        ];
    }

    /**
     * {@inheritDoc}
     */
    public function defineFormFields()
    {
        return 'fields.yaml';
    }

    /**
     * {@inheritDoc}
     */
    public function defineValidationRules()
    {
        return [
            'server_key' => 'required',
            'client_key' => 'required'
        ];
    }

    /**
     * {@inheritDoc}
     */
    public function initConfigData($host)
    {
    }

    /**
     * {@inheritDoc}
     */
    public function registerAccessPoints()
    {
        return array(
            'snap_redirect' => 'processRedirect',
            'snap_notify'   => 'processNotify',
        );
    }

    /**
     * Status field options.
     */
    public function getDropdownOptions()
    {
        return $this->createInvoiceStatusModel()->listStatuses();
    }

    /**
     * Get the URL to Snap's servers
     **/
    public function getFormAction($host)
    {
        if ($host->test_mode) {
            return "https://app.sandbox.midtrans.com/snap/snap.js";
        }
        else {
            return "https://app.midtrans.com/snap/snap.js";
        }
    }

    public function getClientKey($host)
    {
        return $host->client_key;
    }

    protected function getServerKey($host)
    {
        return $host->server_key;
    }

    public function getSnapToken($host, $invoice)
    {
		// Veritrans config
		Veritrans_Config::$serverKey = $host->server_key;
		Veritrans_Config::$isProduction = $host->test_mode ? false : true;
		Veritrans_Config::$isSanitized = $host->is_sanitized ? true : false;
		Veritrans_Config::$is3ds = $host->is_3ds ? true : false;

		// Required
        $totals = (object) $invoice->getTotalDetails();
		$transactionDetails = array(
			'order_id'     => $invoice->id,
			'gross_amount' => (integer) $totals->total, // no decimal allowed for creditcard
		);

        $expiry = array(
			"start_time" => $invoice->created_at->format('Y-m-d H:i:s O'),
			"unit"       => (string) $host->expiry_unit,
			"duration"   => (int) $host->expiry_duration
        );

        $itemDetails = array_map(function($item) {
            return array(
                'id'       => rand(), // TODO: Get the item's ID
                'price'    => (integer) $item['price'],
                'quantity' => $item['quantity'],
                'name'     => $item['description']
            );
        }, $invoice->getLineItemDetails());

		// Optional
        $customer = $invoice->getCustomerDetails();
		$customerDetails = array(
			'first_name'    => $customer['first_name'],
			'last_name'     => $customer['last_name'],
			'email'         => $customer['email'],
			'phone'         => $customer['phone']
		);

		$enabledPayments = $this->getEnabledPayments($host->toArray())->values();

		// Fill transaction details
		$transaction = array(
			'enabled_payments'    => $enabledPayments,
			'transaction_details' => $transactionDetails,
			'expiry'              => $expiry,
			'customer_details'    => $customerDetails,
			/* 'item_details'        => $itemDetails, */
		);

        try {
            $snapToken = Veritrans_Snap::getSnapToken($transaction);
        } catch (Exception $e) {
            Flash::error($e->getMessage());
            return;
        }

        return $snapToken;
    }

    /**
     * {@inheritDoc}
     */
    public function processPaymentForm($data, $invoice)
    {
    }

    public function processRedirect($params)
    {
        try {
            $invoice = $this->createInvoiceModel()->find(get('order_id'));
            if (!$invoice) {
                throw new ApplicationException('Invoice not found');
            }

            return Redirect::to($invoice->getReceiptUrl());

            //
            // If wanna specialize the return type
            //
            // switch($params) {
            //     case 'finish':
            //     case 'unfinish':
            //     case 'error':
            // }
        }
        catch (Exception $ex)
        {
            if ($invoice) {
                $invoice->logPaymentAttempt($ex->getMessage(), 0, [], $_GET, $response);
            }

            throw new ApplicationException($ex->getMessage());
        }
    }

    public function processNotify($params)
    {
        try {
			$response = Input::all();
            $orderId = $response['order_id'];
            $amount = $response['gross_amount'];

            $invoice = $this->createInvoiceModel()
                ->whereTotal($amount)
                ->whereId($orderId)
                ->first();

            if (! $invoice) {
                throw new ApplicationException('Invoice not found');
            }

            if (! $paymentMethod = $invoice->getPaymentMethod()) {
                throw new ApplicationException('Payment method not found');
            }

            if ($paymentMethod->getGatewayClass() != 'Octobro\Midtrans\PaymentTypes\Snap') {
                throw new ApplicationException('Invalid payment method');
            }

            if (! $this->isGenuineNotify($response, $invoice)) {
                throw new ApplicationException('Hacker coming..');
            }

			$transactionStatus = $response['transaction_status'];
			$paymentType = $response['payment_type'];
            $statusMessage = $response['status_message'];
            $configData = $invoice->getPaymentMethod()->config_data;
            $requestData = [
                'expired_time' => $this->getExpiredTime($invoice, $configData)
            ];

            /**
             * If payment has processed from Octommerce Order, don't change any status
             **/
            if ($invoice->related instanceof \Octommerce\Octommerce\Models\Order) {
                if ($invoice->related->isPaid()) return;
            }

            switch ($transactionStatus) {
                case 'capture':
                    if ($paymentType == 'credit_card') {

                        $fraudStatus = $response['fraud_status'];

                        if ($fraudStatus == 'challenge') {
                            $invoice->updateInvoiceStatus($paymentMethod->invoice_challange_status);
                        } else {
                            if ($invoice->markAsPaymentProcessed()) {
                                $invoice->logPaymentAttempt($statusMessage, 1, $requestData, $response, null);
                                $invoice->updateInvoiceStatus($paymentMethod->invoice_paid_status);
                            }
                        }
                    }
                    break;
                case 'settlement':
                    if ($invoice->markAsPaymentProcessed()) {
                        $invoice->logPaymentAttempt($statusMessage, 1, $requestData, $response, null);
                        $invoice->updateInvoiceStatus($paymentMethod->invoice_settlement_status);
                    }
                    break;
                case 'pending':
                    $invoice->logPaymentAttempt($statusMessage, 0, $requestData, $response, null);
                    $invoice->updateInvoiceStatus($paymentMethod->invoice_pending_status);
                    break;
                case 'deny':
                case 'cancel':
                    $invoice->logPaymentAttempt($statusMessage, 0, $requestData, $response, null);
                    $invoice->updateInvoiceStatus($paymentMethod->invoice_cancel_status);
                    break;
                case 'expire':
                    $invoice->logPaymentAttempt($statusMessage, 0, $requestData, $response, null);
                    $invoice->updateInvoiceStatus($paymentMethod->invoice_expire_status);
                    break;
            }
        } catch (Exception $ex) {
            if (isset($invoice) && $invoice) {
                $invoice->logPaymentAttempt($ex->getMessage(), 0, $requestData, $_POST, null);
            }

            throw new ApplicationException($ex->getMessage());
        }
    }

    public function isGenuineNotify($response, $invoice)
    {
       $generatedSignatureKey = $this->generateSignatureKey($response, $invoice);

       if ($response['signature_key'] == $generatedSignatureKey) {
           return true;
       }

       return false;
    }

    protected function generateSignatureKey($response, $invoice)
    {
        $orderId = $response['order_id'];
        $statusCode = $response['status_code'];
        $grossAmount = $response['gross_amount'];
        $serverKey = $invoice->getPaymentMethod()->server_key;

        $data = $orderId . $statusCode . $grossAmount . $serverKey;

        $signature_key = openssl_digest($data, 'sha512');

        return $signature_key;
    }

    public function getEnabledPayments(array $host)
    {
        $channels = [
            'is_credit_card'      => 'credit_card',
            'is_mandiri_clickpay' => 'mandiri_clickpay',
            'is_cimb_clicks'      => 'cimb_clicks',
            'is_bri_epay'         => 'bri_epay',
            'is_telkomsel_cash'   => 'telkomsel_cash',
            'is_xl_tunai'         => 'xl_tunai',
            'is_mandiri_ecash'    => 'mandiri_ecash',
            'is_indosat_dompetku' => 'indosat_dompetku',
            'is_bank_transfer'    => 'bank_transfer',
            'is_echannel'         => 'echannel',
            'is_cstore'           => 'cstore',
            'is_indomaret'        => 'Indomaret',
            'is_gopay'            => 'gopay',
        ];
        $intersectChannels = array_intersect_key($host, $channels);

        $enabledPayments = array_filter($intersectChannels, function($value) {
            return $value;
        });

        $enabledPayments = array_intersect_key($channels, $enabledPayments);

        return collect(array_keys(array_flip($enabledPayments)));
    }

    /**
     * Get expired time based on config data
     *
     * @param string $time
     */
    protected function getExpiredTime($invoice, $configData)
    {
        $unit = '';

        switch ($configData['expiry_unit']) {
            case 'minute':
                $unit = 'addMinutes';
                break;
            case 'day':
                $unit = 'addDays';
                break;
            case 'hour':
                $unit = 'addHours';
                break;
        }

        return $invoice->created_at
            ->{$unit}($configData['expiry_duration'])
            ->format('d F Y - H:i:s e');
    }
}
