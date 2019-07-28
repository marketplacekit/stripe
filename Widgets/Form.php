<?php

namespace Modules\Stripe\Widgets;

use Arrilot\Widgets\AbstractWidget;
use App\Models\PaymentGateway;
use App\Models\PaymentProvider;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Http\Requests\UpdateUserProfile;
use Image;
use Storage;
use GeoIP;
use Date;
use Log;

class Form extends AbstractWidget
{
    /**
     * The configuration array.
     *
     * @var array
     */
    protected $config = [];

    /**
     * Treat this method as a controller action.
     * Return view() or other content to display.
     */
    public function run()
    {
        //
        $countries = collect(json_decode(file_get_contents(resource_path("data/stripe-countries.json")), true));
        $country = request()->input('country', GeoIP::getCountry());
        $account = [];
        $user = auth()->user();
        $stripe_info = $user->payment_gateway('stripe');
        $individual = [];

        $provider = PaymentProvider::where('key', 'stripe')->first();
        \Stripe\Stripe::setApiKey(\Crypt::decryptString($provider->extra_attributes->secret_key));
        if($stripe_info) {
            $account = \Stripe\Account::retrieve($stripe_info->gateway_id);
            $country = $account->country;
            $currency = $countries->firstWhere('id', $country)['default_currency'];

            if($account->external_accounts->data)
                $external_account = $account->external_accounts->data[0];
            #dd($external_account.account_holder_name);

            $countries = $countries->reject(function ($option) use($account) {
                return $option['id'] != $account->country;
            });

            if(isset($account->legal_entity)) {
                $individual = $account->legal_entity;
            } elseif(isset($account->individual)) {
                $individual = $account->individual;
            }
        }

        $currency = $countries->firstWhere('id', $country)['default_currency'];

        $fields = [];
        $exclude_fields = [];
        if($country != 'US' && $country != 'CA' && $country != 'AU') {
            $exclude_fields = ['state'];
        }
        if($country != 'US') {
            $exclude_fields[] = 'ssn_last_4';
        }

        $extra_fields = [];
        if($country == 'HK') {
            $extra_fields[] = ['id' => 'personal_id_number', 'label' => 'Hong Kong Identity Card Number (HKID)'];
        }
        if($country == 'CA') {
            $extra_fields[] = ['id' => 'personal_id_number', 'label' => 'Social Insurance Number (SIN)'];
        }
        if($country == 'SG') {
            $extra_fields[] = ['id' => 'personal_id_number', 'label' => 'National Registration Identity Card '];
        }

        $fields[] = ['id' => 'account_number', 'label' => 'Account number'];
        if($country == 'AU') {
            $fields[] = ['id' => 'routing_number', 'label' => 'BSB number'];
        }
        if($country == 'BR') {
            $fields[] = ['id' => 'bank_code', 'label' => 'Bank code'];
            $fields[] = ['id' => 'branch_code', 'label' => 'Branch code'];
        }
        elseif($country == 'CA') {
            $fields[] = ['id' => 'institution_number', 'label' => 'Institution Number'];
            $fields[] = ['id' => 'transit_number', 'label' => 'Transit Number'];
        }
        elseif($country == 'US') {
            $fields[] = ['id' => 'routing_number', 'label' => 'Routing number'];
        }
        elseif($country == 'HK') {
            $fields[] = ['id' => 'clearing_code', 'label' => 'Clearing code'];
            $fields[] = ['id' => 'branch_code', 'label' => 'Branch code'];
        }
        elseif($country == 'JP') {
            $fields[] = ['id' => 'bank_name', 'label' => 'Bank Name'];
            $fields[] = ['id' => 'branch_name', 'label' => 'Branch Name'];
            $fields[] = ['id' => 'bank_code', 'label' => 'Bank code'];
            $fields[] = ['id' => 'branch_code', 'label' => 'Branch code'];
        }
        elseif($country == 'SG') {
            $fields[] = ['id' => 'bank_code', 'label' => 'bank_code'];
            $fields[] = ['id' => 'branch_code', 'label' => 'branch_code'];
        }
        elseif($country == 'NZ') {
            $fields[] = ['id' => 'routing_number', 'label' => 'Routing number'];
        }
        elseif($country == 'GB') {
            $fields[] = ['id' => 'routing_number', 'label' => 'Sort Code'];
        }
        else {
            $fields = [['id' => 'account_number', 'label' => 'IBAN']];
        }

        $days = array_combine(range(1,31), range(1,31));
        $months = [];
        Date::setLocale(config('app.locale'));
        for($m=1; $m<=12; ++$m){
            $months[$m] = ucfirst(Date::parse(mktime(0, 0, 0, $m, 1))->format('F'));
        }
        $years = range(date('Y')-100, date('Y'));
        $years = array_combine($years, $years);

        $payment_providers = PaymentProvider::with(['identifier' => function ($query) {
            $query->where('user_id', auth()->user()->id);
        }])->where('is_enabled', 1)->get();

        $config = $this->config;
        #return view('account.bank_account', compact('config', 'user', 'countries', 'fields', 'country', 'currency', 'days', 'months', 'years', 'exclude_fields', 'account', 'extra_fields', 'external_account', 'payment_providers', 'individual'));

        return view('stripe::connect',
            compact('config', 'user', 'countries', 'fields', 'country', 'currency', 'days', 'months', 'years', 'exclude_fields', 'account', 'extra_fields', 'external_account', 'payment_providers', 'individual')
        );
    }
}
