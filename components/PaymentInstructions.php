<?php namespace Octobro\Midtrans\Components;

use Twig;
use Cms\Classes\ComponentBase;

class PaymentInstructions extends ComponentBase
{
    public $paymentData, $paymentChannel;

    public function componentDetails()
    {
        return [
            'name'        => 'snapPaymentInstructions Component',
            'description' => 'No description provided yet...'
        ];
    }

    public function defineProperties()
    {
        return [];
    }

    public function onRun()
    {
        $invoice = $this->page['invoice'];

        if (!$invoice) return;

        $this->paymentData = $paymentData = $this->getInvoicePaymentData($invoice);

        $this->paymentChannel = is_array($paymentData) ? array_get($paymentData, 'payment_type') : null;
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
