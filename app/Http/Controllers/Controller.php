<?php

namespace App\Http\Controllers;

use App\Models\Misc\Setting;
use App\Service\Balance\CheckoutAmountCalculator;
use App\Service\Balance\CurrencyConverter;
use App\Service\File\FileUploadService;
use App\Service\File\ImageUploadService;
use App\Service\Misc\TransactionRecord;
use App\Service\Notification\EmailService;
use App\Service\Notification\GrantNotificationService;
use App\Service\Notification\NotificationService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Foundation\Validation\ValidatesRequests;
use Illuminate\Routing\Controller as BaseController;

class Controller extends BaseController
{
    protected $api_base_url;
    protected $site_url;
    protected $emailService;
    protected $notification;
    protected $grantNotification;
    protected $transaction;
    protected $fileUpload;
    protected $imageUpload;
    protected $checkoutCalculator;

    protected $tujitume_fee;
    protected $usdToKes;

    public function __construct() {
        $this->emailService = new EmailService();
        $this->notification = new NotificationService();
        $this->grantNotification = new GrantNotificationService();
        $this->transaction = new TransactionRecord();
        $this->fileUpload = new FileUploadService();
        $this->imageUpload = new ImageUploadService();
        $this->checkoutCalculator = new CheckoutAmountCalculator();

        $this->api_base_url = config('app.api_base_url');
        $this->site_url = 'https://tujitume.com/';
        $this->tujitume_fee = (float) Setting::where('key', 'tujitume_fee')->first()?->value ?? 5;

        $this->converter = new CurrencyConverter();
        $this->usdToKes = $this->converter->UsdToKes();
    }

    use AuthorizesRequests, ValidatesRequests;
}
