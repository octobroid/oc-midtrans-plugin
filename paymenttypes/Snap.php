<?php namespace Octobro\Midtrans\PaymentTypes;

use Twig;
use Input;
use Flash;
use Redirect;
use Exception;
use Carbon\Carbon;
use Midtrans\Snap as MidtransSnap;
use Midtrans\Config;
use ApplicationException;
use Responsiv\Pay\Models\InvoiceLog;
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
        // If already paid or utilized, don't get token
        if ($invoice->status->code != 'draft') return;

		// Midtrans config
		Config::$serverKey = $host->server_key;
		Config::$isProduction = $host->test_mode ? false : true;
		Config::$isSanitized = $host->is_sanitized ? true : false;
		Config::$is3ds = $host->is_3ds ? true : false;

		// Required
        $totals = (object) $invoice->getTotalDetails();
		$transactionDetails = array(
			'order_id'     => $invoice->id,
			'gross_amount' => (integer) $totals->total, // no decimal allowed for creditcard
		);

        if ($invoice->due_at) {
            $expiry = array(
                "start_time" => $invoice->created_at->format('Y-m-d H:i:s O'),
                "unit"       => 'minute',
                "duration"   => $invoice->created_at->diffInMinutes($invoice->due_at),
            );
        } else {
            $expiry = array(
                "start_time" => Carbon::now()->format('Y-m-d H:i:s O'),
                "unit"       => (string) $host->expiry_unit,
                "duration"   => (int) $host->expiry_duration
            );
        }

        $itemDetails = $invoice->items->map(function ($item) {
            return [
                'id'       => $item->id,
                'price'    => (integer) $item->price,
                'quantity' => $item->quantity,
                'name'     => $item->description,
            ];
        })->toArray();

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
			'item_details'        => $itemDetails,
		);

        try {
            $snapToken = MidtransSnap::getSnapToken($transaction);
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
            $invoice = get('order_id') ? $this->createInvoiceModel()->find(get('order_id')) : null;

            if (!$invoice && get('id')) {
                $log = InvoiceLog::where('response_data', 'like', '%"transaction_id":"' . get('id') . '"%')->where('created_at', '>=', Carbon::now()->subHours(1))->first();

                if ($log) $invoice = $this->createInvoiceModel()->find($log->invoice_id);
            }

            if (!$invoice) {
                throw new ApplicationException('Invoice not found');
            }

            $status = array_get($params, 0);

            //
            // If wanna specialize the return type
            //
            switch ($status) {
                case 'finish':
                    if (get('transaction_status') == 'pending') {
                        return Redirect::to($invoice->getReceiptUrl());
                    }
                    return Redirect::to($invoice->getReceiptUrl())->with('success', true);
                case 'error':
                    return Redirect::to($invoice->getReceiptUrl())->with('error', true);
                case 'unfinish':
                    return Redirect::to($invoice->getReceiptUrl());
            }

            return Redirect::to($invoice->getReceiptUrl());
        }
        catch (Exception $ex)
        {
            if ($invoice) {
                $invoice->logPaymentAttempt($ex->getMessage(), 0, [], $_GET, null);
            }

            throw new ApplicationException($ex->getMessage());
        }
    }

    public function processNotify($params)
    {
        try {
			$response = Input::all();

            $orderId = Input::get('order_id');
            $amount  = Input::get('gross_amount');

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
                throw new ApplicationException('Hacker coming');
            }

            $transactionTime   = Input::get('transaction_time');
			$transactionStatus = Input::get('transaction_status');
            $statusCode        = Input::get('status_code');
            $fraudStatus       = Input::get('fraud_status');
			$paymentType       = Input::get('payment_type');
            $statusMessage     = Input::get('status_message');

            $configData        = $invoice->getPaymentMethod()->config_data;
            $requestData       = [
                'expired_time' => $this->getExpiredTime($invoice, $configData)
            ];

            /**
             * If payment has processed from Octommerce Order, don't change any status
             **/
            if ($invoice->related instanceof \Octommerce\Octommerce\Models\Order) {
                if ($invoice->related->isPaid()) return;
            }

            /**
             * Perform based on status code
             * https://snap-docs.midtrans.com/#status-code
             */
            switch ($statusCode) {
                case 200: // Success
                    if ($fraudStatus != 'accept' && $transactionStatus != 'settlement' && $transactionStatus != 'capture') {
                        $invoice->logPaymentAttempt($transactionStatus, 0, $requestData, $response, null);
                        break;
                    }

                    if ($invoice->markAsPaymentProcessed()) { // error?
                        $invoice->logPaymentAttempt($transactionStatus, 1, $requestData, $response, null);
                        $invoice->updateInvoiceStatus($paymentMethod->invoice_paid_status);
                    }
                    break;
                case 201: // Challenge or Pending
                    $invoice->logPaymentAttempt($transactionStatus, 0, $requestData, $response, null);
                    $invoice->updateInvoiceStatus($paymentMethod->invoice_pending_status);

                    // Check if due at is not available
                    if (!$invoice->due_at) {
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
                
                        $invoice->due_at = Carbon::parse($transactionTime)->{$unit}($configData['expiry_duration']);
                        $invoice->save();
                    }

                    break;
                case 202: // Denied or Expired
                    $invoice->logPaymentAttempt($transactionStatus, 0, $requestData, $response, null);
                    $invoice->updateInvoiceStatus($paymentMethod->invoice_cancel_status);
                    break;
                case substr($statusCode, 0, 1) == 3: // 3xx: Moved Permanently 
                case substr($statusCode, 0, 1) == 4: // 4xx: Validation Error, Expired, or Missing
                case substr($statusCode, 0, 1) == 5: // 5xx: Internal Server Error
                    $invoice->logPaymentAttempt($statusCode, 0, $requestData, $response, null);
                    break;
            }
        } catch (Exception $ex) {
            if (isset($invoice) && $invoice) {
                $invoice->logPaymentAttempt($ex->getMessage(), 0, [], $_GET, null);
            }

            throw $ex;
        }
    }

    public function isGenuineNotify($response, $invoice)
    {
       $generatedSignatureKey = $this->generateSignatureKey($response, $invoice);

       if (array_get($response, 'signature_key') == $generatedSignatureKey) {
           return true;
       }

       return false;
    }

    protected function generateSignatureKey($response, $invoice)
    {
        $orderId     = array_get($response, 'order_id');
        $statusCode  = array_get($response, 'status_code');
        $grossAmount = array_get($response, 'gross_amount');
        $serverKey   = $invoice->getPaymentMethod()->server_key;

        $data = $orderId . $statusCode . $grossAmount . $serverKey;

        $signature_key = openssl_digest($data, 'sha512');

        return $signature_key;
    }

    public function getEnabledPayments(array $host)
    {
        $channels = [
            'is_credit_card'      => 'credit_card',
            'is_bca_va'           => 'bca_va',
            'is_permata_va'       => 'permata_va',
            'is_bni_va'           => 'bni_va',
            'is_echannel'         => 'echannel',
            'is_gopay'            => 'gopay',
            'is_bca_klikbca'      => 'bca_klikbca',
            'is_bca_klikpay'      => 'bca_klikpay',
            'is_mandiri_clickpay' => 'mandiri_clickpay',
            'is_cimb_clicks'      => 'cimb_clicks',
            'is_danamon_online'   => 'danamon_online',
            'is_bri_epay'         => 'bri_epay',
            'is_mandiri_ecash'    => 'mandiri_ecash',
            'is_indomaret'        => 'indomaret',
            'is_alfamart'         => 'alfamart',
            'is_akulaku'          => 'akulaku',
        ];
        $intersectChannels = array_intersect_key($host, $channels);

        $enabledPayments = array_filter($intersectChannels, function($value) {
            return $value;
        });

        $enabledPayments = array_intersect_key($channels, $enabledPayments);

        return collect(array_keys(array_flip($enabledPayments)));
    }

    public function getPaymentInstructions($invoice)
    {
        $paymentData = $this->getInvoicePaymentData($invoice);
        $paymentChannel = is_array($paymentData) ? array_get($paymentData, 'payment_type') : null;

        if (!$paymentChannel) return;

        $partialPath = plugins_path('octobro/midtrans/paymenttypes/snap/_' . $paymentChannel . '.htm');

        if (!file_exists($partialPath)) return;

        return Twig::parse(file_get_contents($partialPath), ['data' => $paymentData]);
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

    protected function getInvoicePaymentData($invoice)
    {
        $logs = $invoice->payment_log()
            ->get();

        $data = [];

        foreach ($logs as $log) {
            $responseData = $log->response_data;
            $paymentType = $data['payment_type'] = array_get($responseData, 'payment_type') ?: array_get($data, 'payment_type');
            $data['gross_amount'] = array_get($responseData, 'gross_amount') ?: array_get($data, 'gross_amount');
        }

        switch (array_get($data, 'payment_type')) {
            case 'bank_transfer':
                if (isset($responseData['va_numbers'])) {
                    $data['bank']   = array_get($responseData['va_numbers'][0], 'bank');
                    $data['acc_no'] = array_get($responseData['va_numbers'][0], 'va_number');
                }

                if (isset($responseData['permata_va_number'])) {
                    $data['bank']   = 'permata';
                    $data['acc_no'] = $responseData['permata_va_number'];
                }

                break;
            case 'echannel':
                $data['biller_code'] = array_get($responseData, 'biller_code');
                $data['bill_key']    = array_get($responseData, 'bill_key');
                break;
        }

        return $data;
    }
}
