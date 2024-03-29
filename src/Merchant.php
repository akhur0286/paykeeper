<?php

namespace akhur0286\paykeeper;

use akhur0286\paykeeper\models\PaykeeperInvoice;
use Yii;
use yii\base\BaseObject;
use yii\base\ErrorException;
use yii\base\InvalidConfigException;
use yii\helpers\Json;
use yii\helpers\Url;
use yii\web\Response;
use yii\helpers\StringHelper;

class Merchant extends BaseObject
{
    public $merchantLogin;

    public $merchantPassword;

    /**
     * @var string Адрес платежного шлюза
     */
    public $serverUrl;

    public $orderModel;

    /**
     * Создание оплаты редеректим в шлюз сберабнка
     * @param $orderID - id заказа
     * @param $sum - сумма заказа
     * @param null $additionalData - доп данные(email, телефон и тд)
     * @return mixed
     */
    public function create($orderID, $sum, $additionalData = null)
    {
        $relatedModel = $this->getRelatedModel();

        $invoice = PaykeeperInvoice::findOne(['related_id' => $orderID, 'related_model' => $relatedModel]);

        if ($invoice) {
            return $invoice->url;
        }

        $data = [
            'orderid' => $orderID,
            'pay_amount' => $sum,
            'service_name' => '',
        ];
        if (isset($additionalData['service_name'])) {
            $data['service_name'] = $additionalData['service_name'];
        }
        if (isset($additionalData['clientid'])) {
            $data['clientid'] = $additionalData['clientid'];
        }
        if (isset($additionalData['email'])) {
            $data['client_email'] = $additionalData['email'];
        }
        if (isset($additionalData['phone'])) {
            $data['client_phone'] = $additionalData['phone'];
            $data['phone'] = $additionalData['phone'];
        }

        $response = $this->sendGet('/info/settings/token/', $data);

        if (!$response || !isset($response['token'])) {
            throw new ErrorException('Возникла ошибка при оплате');
        }

        $data['token'] = $response['token'];

        $response = $this->sendPost( '/change/invoice/preview/', $data);

        if (!$response || !isset($response['invoice_id'])) {
            throw new ErrorException('Возникла ошибка при оплате');
        }

        $invoiceID = $response['invoice_id'];
        $formUrl = $this->serverUrl . '/bill/' . $invoiceID . '/';;

        PaykeeperInvoice::add($orderID, $this->relatedModel, $invoiceID, $formUrl, $data);

        return $formUrl;
    }

    /**
     * Откправка запроса в api сбербанка
     * @param $action string типа запрос
     * @param $data array Параметры которые передаём в запрос
     * @return mixed Ответ сбербанка
     */
    public function sendGet($action, $data)
    {
        $authData = [
            'userName' => $this->merchantLogin,
            'password' => $this->merchantPassword,
        ];
        $data = array_merge($authData,$data);

        $url = $this->serverUrl . $action;

        $base64 = base64_encode($this->merchantLogin . ':' . $this->merchantPassword);
        $headers = [];
        array_push($headers, 'Content-Type: application/x-www-form-urlencoded');
        array_push($headers, 'Authorization: Basic ' . $base64);

        $curl = curl_init();
        curl_setopt($curl, CURLOPT_URL, $url);
        curl_setopt($curl, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($curl, CURLOPT_CUSTOMREQUEST, 'GET');
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($curl, CURLOPT_HEADER, false);
        $out = curl_exec($curl);

        return Json::decode($out);
    }

    public function sendPost($action, $data)
    {
        $base64 = base64_encode($this->merchantLogin . ':' . $this->merchantPassword);
        $headers = [];
        array_push($headers, 'Content-Type: application/x-www-form-urlencoded');
        array_push($headers, 'Authorization: Basic ' . $base64);

        $curl = curl_init();
        $request = http_build_query($data);

        curl_setopt($curl, CURLOPT_URL, $this->serverUrl . $action);
        curl_setopt($curl, CURLOPT_HTTPHEADER , $headers);
        curl_setopt($curl, CURLOPT_CUSTOMREQUEST, 'POST');
        curl_setopt($curl, CURLOPT_POSTFIELDS, $request);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($curl, CURLOPT_HEADER, false);
        $response = curl_exec($curl);
        curl_close($curl);

        return Json::decode($response);
    }

    public function getRelatedModel()
    {
        return strtolower(StringHelper::basename($this->orderModel));
    }
}
