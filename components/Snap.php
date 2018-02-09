<?php namespace Octobro\Midtrans\Components;

use Event;
use Exception;
use Veritrans_Snap;
use Veritrans_Config;
use ApplicationException;
use Cms\Classes\ComponentBase;
use Responsiv\Pay\Models\Invoice;
use Responsiv\Pay\Models\PaymentMethod;

class Snap extends ComponentBase
{
    public function componentDetails()
    {
        return [
            'name'        => 'Snap Component',
            'description' => 'No description provided yet...'
        ];
    }

    public function defineProperties()
    {
        return [];
    }

    public function onGetToken()
    {
        $payMethod = PaymentMethod::whereCode(post('payment_code'))->first();
        $invoice = Invoice::whereHash(post('invoice_hash'))->first();

        if ( ! $payMethod) {
            throw new ApplicationException('Payment method not found');
        }

        if ( ! $invoice) {
            throw new ApplicationException('Invoice not found');
        }

        $host = (object) $payMethod->config_data;

		// Veritrans config
		Veritrans_Config::$serverKey = $host->server_key;
		Veritrans_Config::$isProduction = $host->test_mode ? false : true;
		Veritrans_Config::$isSanitized = $host->is_sanitized ? true : false;
		Veritrans_Config::$is3ds = $host->is_3ds ? true : false;

		// Required
        $totals = (object) $invoice->getTotalDetails();
		$transactionDetails = array(
			'order_id'     => rand(),
			'gross_amount' => (integer) $totals->total, // no decimal allowed for creditcard
		);

        $expiry = array(
			"start_time" => $invoice->created_at->format('Y-m-d H:i:s O'),
			"unit"       => (string) $host->expiry_unit,
			"duration"   => (int) $host->expiry_duration
        );

        /**
         * Allow user to manipulate expiry by reference
         **/
        Event::fire('octobro.midtrans.afterSetExpiry', [$invoice, &$expiry]);

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

		$enabledPayments = $this->getEnabledPayments($payMethod->config_data);

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
            throw new \AjaxException($e->getMessage());
        }

        return [
            'token' => $snapToken
        ];
    }

    protected function getEnabledPayments(array $host)
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
        ];
        $intersectChannels = array_intersect_key($host, $channels);

        $enabledPayments = array_filter($intersectChannels, function($value) {
            if ($value) return $value;
        });

        $enabledPayments = array_intersect_key($channels, $enabledPayments);
        
        return array_keys(array_flip($enabledPayments));
    }
}
