<?php
/**
 * @copyright Copyright(c) 2022 aircheng.com
 * @file stripe.php
 * @brief stripe支付插件
 * @author nswe
 * @date 2022/8/20 11:37:14
 * @version 5.12
 */

//引入类库
require_once(dirname(__FILE__)."/stripe-php-master/init.php");
class stripe extends paymentPlugin
{
	//支付插件名称
    public $name = 'Stripe支付';
	public $currency = 'aud';//货币

	public function getSubmitUrl()
	{
		return '';
	}

	/**
	 * @see paymentplugin::getSendData()
	 * 此页面会在整个支付过程中一直跳转，并且URL中有参数变化
	 */
    public function getSendData($payment)
    {
        /* Configuration parameters */
        $merPublic  = $payment['merPublic'];
        $merPrivate = $payment['merPrivate'];

        /* Order related parameters */
        $order_no = $payment['M_OrderNO'];
        $order_amount = $payment['M_Amount']*100;

		//生成临时变量保存付款单ID参数
		$sessionKey = $order_no.'paymentIntentID';

		try
		{
			$stripe = new \Stripe\StripeClient($merPrivate);

			//付款单ID
			$paymentIntentID = ISafe::get($sessionKey);
			if($paymentIntentID)
			{
				$issetPaymentIntents = $stripe->paymentIntents->retrieve($paymentIntentID,[]);
				if(is_string($issetPaymentIntents) || !$issetPaymentIntents->client_secret)
				{
					$paymentIntentID = '';
				}
				else
				{
					//支付成功
					if($issetPaymentIntents->status == 'succeeded')
					{
						$url = IUrl::getHost().IUrl::creatUrl('/ucenter/order');
						header('location: '.$url);
						exit;
					}

					//核对一下金额是否正确
					if($issetPaymentIntents->amount != $order_amount)
					{
						$stripe->paymentIntents->update($paymentIntentID,
						[
							'metadata' => ['order_no' => $order_no],
							'amount'   => $order_amount,
						]);
					}
					$client_secret = $issetPaymentIntents->client_secret;
				}
			}

			if(!$paymentIntentID)
			{
				$paymentIntents = $stripe->paymentIntents->create([
					'amount'   => $order_amount,
					'currency' => $this->currency,
					'payment_method_types' => ['card','wechat_pay'],
					'metadata' => ['order_no' => $order_no],
				]);
				$client_secret = $paymentIntents->client_secret;
				ISafe::set($sessionKey,$paymentIntents->id);
			}

			$payment['client_secret'] = $client_secret;
			return $payment;
		}
		catch(Exception $e)
		{
			die($e->getMessage());
		}
    }

	/**
	 * @see paymentplugin::doPay()
	 */
    public function doPay($sendData)
    {
		$client_secret = $sendData['client_secret'];
		$merPublic     = $sendData['merPublic'];
		$webPayDir     = IUrl::creatUrl().'plugins/payments/pay_stripe/template/';
		$backUrl       = IUrl::getHost().IUrl::creatUrl('/block/doPay/order_id/'.$sendData['M_OrderId'].'/payment_id/19');
		include(dirname(__FILE__).'/template/pay.php');
    }

	/**
	 * @see paymentplugin::notifyStop()
	 */
	public function notifyStop()
	{
		http_response_code(200);
	}

	/**
	 * @see paymentplugin::callback()
	 */
	public function callback($callbackData,&$paymentId,&$money,&$message,&$orderNo)
	{

	}

	/**
	 * @see paymentplugin::serverCallback()
	 */
	public function serverCallback($callbackData,&$paymentId,&$money,&$message,&$orderNo)
	{
		// This is your Stripe CLI webhook secret for testing your endpoint locally.
		$endpoint_secret = Payment::getConfigParam($paymentId,'webhook');

		$payload = file_get_contents('php://input');
		$sig_header = $_SERVER['HTTP_STRIPE_SIGNATURE'];
		$event = null;

		try
		{
			$event = \Stripe\Webhook::constructEvent($payload, $sig_header, $endpoint_secret);
		}
		catch(\UnexpectedValueException $e)
		{
			// Invalid payload
			http_response_code(400);
			exit();
		}
		catch(\Stripe\Exception\SignatureVerificationException $e)
		{
			// Invalid signature
			http_response_code(400);
			exit();
		}

		// Handle the event
		switch($event->type)
		{
			//付款成功
			case 'payment_intent.succeeded':
			{
				$paymentIntent = $event->data->object;
				$logObj = new IFileLog('payment/stripe/'.date('Y-m-d').'.log');
				$logObj->write(['payment_intent.succeeded事件' => var_export($paymentIntent,true)]);

				$orderNo = $paymentIntent->metadata['order_no'];
				$money   = $paymentIntent->amount/100;

				//记录回执流水号
				if($paymentIntent->id)
				{
					$this->recordTradeNo($orderNo,$paymentIntent->id);
				}
				return true;
			}
			break;

			//账单退款
			case 'charge.refunded':
			{
				$charge = $event->data->object;
				$logObj = new IFileLog('payment/stripe/'.date('Y-m-d').'.log');
				$logObj->write(['charge.refunded事件' => var_export($charge,true)]);
				$this->notifyStop();
			}
			break;
		}
	}

	/**
	 * @brief 执行退款接口
	 * @param array $payment 退款信息接口
	 */
	public function doRefund($payment)
	{
        $merPublic  = $payment['merPublic'];
        $merPrivate = $payment['merPrivate'];

		$transaction_id = $payment['M_TransactionId'];
		$refund_fee     = $payment['M_Refundfee']*100;

		$stripe = new \Stripe\StripeClient($merPrivate);
		$refundsObj = $stripe->refunds->create([
			'payment_intent' => $transaction_id,
			'amount' => $refund_fee,
		]);

		if(is_string($refundsObj))
		{
			return $refundsObj;
		}

		if($refundsObj->status == 'succeeded')
		{
			return true;
		}
		return var_export($refundsObj,true);
	}

	/**
	 * @param 获取配置参数
	 */
	public function configParam()
	{
		$result = array(
			'merPublic'  => '公钥',
			'merPrivate' => '私钥',
			'webhook'    => 'webHook密钥',
		);
		return $result;
	}
}