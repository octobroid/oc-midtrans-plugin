<?php namespace Octobro\Midtrans;

use Backend;
use System\Classes\PluginBase;

/**
 * Midtrans Plugin Information File
 */
class Plugin extends PluginBase
{
    public $require = ['Responsiv.Pay'];

    /**
     * Returns information about this plugin.
     *
     * @return array
     */
    public function pluginDetails()
    {
        return [
            'name'        => 'Midtrans',
            'description' => 'Midtrans payment gateway for Responsiv.Pay plugin.',
            'author'      => 'Octobro',
            'icon'        => 'icon-credit-card'
        ];
    }

    /**
     * Registers any payment gateways implemented in this plugin.
     * The gateways must be returned in the following format:
     * ['className1' => 'alias'],
     * ['className2' => 'anotherAlias']
     */
    public function registerPaymentGateways()
    {
        return [
            'Octobro\Midtrans\PaymentTypes\Snap' => 'midtrans-snap',
        ];
    }

    /**
     * Registers any front-end components implemented in this plugin.
     *
     * @return array
     */
    public function registerComponents()
    {
        return [
            'Octobro\Midtrans\Components\Snap' => 'snapChannel',
            'Octobro\Midtrans\Components\PaymentInstructions' => 'snapPaymentInstructions'
        ];
    }
}
