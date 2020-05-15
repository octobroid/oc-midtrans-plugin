<?php namespace Octobro\Midtrans\Components;

use Event;
use Exception;
use Carbon\Carbon;
use Midtrans\Snap as MidtransSnap;
use Midtrans\Config;
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
            $snapToken = MidtransSnap::getSnapToken($transaction);
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
            if ($value) return $value;
        });

        $enabledPayments = array_intersect_key($channels, $enabledPayments);
        
        return array_keys(array_flip($enabledPayments));
    }
}
