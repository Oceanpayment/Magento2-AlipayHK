<?php 

namespace Oceanpayment\AlipayHK\Controller\Payment; 


use Magento\Framework\Controller\ResultFactory;
use Magento\Quote\Api\CartManagementInterface;

class Notice extends \Magento\Framework\App\Action\Action
{

    const PUSH          = "[PUSH]";
    const BrowserReturn = "[Browser Return]";

    protected $_processingArray = array('processing', 'complete');


    /**
     * Customer session model
     *
     * @var \Magento\Customer\Model\Session
     */
    protected $_customerSession;
    protected $resultPageFactory;
    protected $checkoutSession;
    protected $orderRepository;
    protected $_scopeConfig;
    protected $_orderFactory;
    protected $creditmemoSender;
    protected $orderSender;

    /**
     * @param \Magento\Framework\App\Action\Context $context
     * @param \Magento\Customer\Model\Session $customerSession
     */
    public function __construct(
        \Magento\Customer\Model\Session $customerSession,
        \Magento\Framework\App\Action\Context $context,
        \Oceanpayment\AlipayHK\Model\PaymentMethod $paymentMethod,
        \Magento\Sales\Api\OrderRepositoryInterface $orderRepository,
        \Magento\Sales\Model\OrderFactory $orderFactory,
        \Magento\Checkout\Model\Session $checkoutSession,
        \Magento\Sales\Model\Order\Email\Sender\CreditmemoSender $creditmemoSender,
        \Magento\Sales\Model\Order\Email\Sender\OrderSender $orderSender,
        \Magento\Framework\App\Config\ScopeConfigInterface $scopeConfig
    ) {
        $this->_customerSession = $customerSession;
        $this->checkoutSession = $checkoutSession;
        $this->orderRepository = $orderRepository;
        parent::__construct($context);
        $this->_scopeConfig = $scopeConfig;
        $this->_orderFactory = $orderFactory;
        $this->_paymentMethod = $paymentMethod;
        $this->creditmemoSender = $creditmemoSender;
        $this->orderSender = $orderSender;
    }


    protected function _createInvoice($order)
    {
        if (!$order->canInvoice()) {
            return;
        }
        
        $invoice = $order->prepareInvoice();
        if (!$invoice->getTotalQty()) {
            throw new \RuntimeException("Cannot create an invoice without products.");
        }

        $invoice->setRequestedCaptureCase(\Magento\Sales\Model\Order\Invoice::CAPTURE_OFFLINE);
        $invoice->register();
        $order->addRelatedObject($invoice);
    }

    public function execute()
    {
        //?????????????????????XML
        $xml_str = file_get_contents("php://input");
        
     
        //?????????????????????????????????xml
        if($this->xml_parser($xml_str)){
            $xml = simplexml_load_string($xml_str);
                
            //????????????????????????$return_info
            $return_info['response_type']    = (string)$xml->response_type;
            $return_info['account']          = (string)$xml->account;
            $return_info['terminal']         = (string)$xml->terminal;
            $return_info['payment_id']       = (string)$xml->payment_id;
            $return_info['order_number']     = (string)$xml->order_number;
            $return_info['order_currency']   = (string)$xml->order_currency;
            $return_info['order_amount']     = (string)$xml->order_amount;
            $return_info['payment_status']   = (string)$xml->payment_status;
            $return_info['payment_details']  = (string)$xml->payment_details;
            $return_info['signValue']        = (string)$xml->signValue;
            $return_info['order_notes']      = (string)$xml->order_notes;
            $return_info['card_number']      = (string)$xml->card_number;
            $return_info['payment_authType'] = (string)$xml->payment_authType;
            $return_info['payment_risk']     = (string)$xml->payment_risk;
            $return_info['methods']          = (string)$xml->methods;
            $return_info['payment_country']  = (string)$xml->payment_country;
            $return_info['payment_solutions']= (string)$xml->payment_solutions;

            //????????????
            $model = $this->_paymentMethod;

            if($model->getConfigData('logs')) {
                //??????????????????
                $this->returnLog(self::PUSH, $xml_str);
            }

            $order = $this->_orderFactory->create()->loadByIncrementId($return_info['order_number']);

            $history = ' (payment_id:'.$return_info['payment_id'].' | order_number:'.$return_info['order_number'].' | '.$return_info['order_currency'].':'.$return_info['order_amount'].' | payment_details:'.$return_info['payment_details'].')';

            //?????????????????????
            $authType = '';
            if($return_info['payment_authType'] == 1){
                if($return_info['payment_status'] == 1){
                    $authType = '(Capture)';
                }elseif($return_info['payment_status'] == 0){
                    $authType = '(Void)';
                }
            }

            switch($this->validated($order)){
                case 1:
                    //????????????
                    $order->setState($model->getConfigData('success_order_status'));
                    $order->setStatus($model->getConfigData('success_order_status'));
                    $order->addStatusToHistory($model->getConfigData('success_order_status'), __(self::PUSH.$authType.'Payment Success!'.$history));
                    
                    //????????????
                    $this->orderSender->send($order, true);
                    
                    //??????Invoice
                    if ($model->getConfigData('invoice')){
                        $this->_createInvoice($order);
                    }

                    $order->save();
                    break;
                case 0:
                    //????????????
                    $order->setState($model->getConfigData('failure_order_status'));
                    $order->setStatus($model->getConfigData('failure_order_status'));
                    $order->addStatusToHistory($model->getConfigData('failure_order_status'), __(self::PUSH.$authType.'Payment Failed!'.$history));
                    $order->save();
                    break;
                case -1:
                    //???????????????
                    $order->setState($model->getConfigData('pre_auth_order_status'));
                    $order->setStatus($model->getConfigData('pre_auth_order_status'));
                    $order->addStatusToHistory($model->getConfigData('pre_auth_order_status'), __(self::PUSH.'(Pre-auth)Payment Pending!'.$history));
                    $order->save();
                    break;
                case 2:
                    //?????????????????????????????????
                    $order->setState($model->getConfigData('success_order_status'));
                    $order->setStatus($model->getConfigData('success_order_status'));
                    $order->addStatusToHistory($model->getConfigData('success_order_status'), __(self::PUSH.'Payment Success!'.$history));
                    $order->save();
                    break;
                case '10000':
                    //10000:Payment is declined ???????????????
                    $order->setState($model->getConfigData('high_risk_order_status'));
                    $order->setStatus($model->getConfigData('high_risk_order_status'));
                    $order->addStatusToHistory($model->getConfigData('high_risk_order_status'), __(self::PUSH.'(High Risk)Payment Failed!'.$history));
                    $order->save();
                case '20061':
                    //???????????????
                    break;
                case 999:
                    //??????????????????????????????
                    break;
                default:

            }

            return "receive-ok";
//            exit;

        }

    }


    private function validated($order)
    {
        //????????????
        $model            = $this->_paymentMethod;      
        
        //????????????
        $account          = $model->getConfigData('account');

        //???????????????
        $terminal         = $this->getRequest()->getParam('terminal');
        
        //???????????????   ????????????3D??????
        if($terminal == $model->getConfigData('terminal')){
            $securecode = $model->getConfigData('securecode');
        }elseif($terminal == $model->getConfigData('secure/secure_terminal')){
            //3D
            $securecode = $model->getConfigData('secure/secure_securecode');
        }else{
            $securecode = '';
        }
        
        //??????Oceanpayment??????????????????
        $payment_id       = $this->getRequest()->getParam('payment_id');
        
        //?????????????????????
        $order_number     = $this->getRequest()->getParam('order_number');
        
        //??????????????????
        $order_currency   = $this->getRequest()->getParam('order_currency');
        
        //??????????????????
        $order_amount     = $this->getRequest()->getParam('order_amount');
        
        //??????????????????
        $payment_status   = $this->getRequest()->getParam('payment_status');
        
        //??????????????????
        $payment_details  = $this->getRequest()->getParam('payment_details');
        
        //??????????????????
        $getErrorCode     = explode(':', $payment_details);  
        
        //??????????????????
        $payment_solutions = $this->getRequest()->getParam('payment_solutions');
        
        //????????????
        $order_notes       = $this->getRequest()->getParam('order_notes');
        
        //????????????????????????
        $payment_risk      = $this->getRequest()->getParam('payment_risk');
        
        //???????????????????????????
        $card_number       = $this->getRequest()->getParam('card_number');
        
        //??????????????????
        $payment_authType  = $this->getRequest()->getParam('payment_authType');
        
        //??????????????????
        $back_signValue    = $this->getRequest()->getParam('signValue');
        
        //SHA256??????
        $local_signValue = hash("sha256",$account.$terminal.$order_number.$order_currency.$order_amount.$order_notes.$card_number.
                    $payment_id.$payment_authType.$payment_status.$payment_details.$payment_risk.$securecode);
 

        //????????????
        if(strtoupper($local_signValue) == strtoupper($back_signValue)){
            
            //????????????????????????
            if($payment_authType == 0){
                //?????????????????????????????????
                if(in_array($order->getState(), $this->_processingArray)){
                    return 1;
                }
            }

            //????????????
            if ($payment_status == 1) {
                return 1;
            } elseif ($payment_status == -1) {
                return -1;
            } elseif ($payment_status == 0) {

                //10000:Payment is declined ???????????????
                if($getErrorCode[0] == '10000'){
                    return '10000';
                }
                //???????????????????????????????????????????????? 20061
                if($getErrorCode[0] == '20061'){
                    return '20061';
                }

                return 0;
            }
        }else{
            return 999;
        }
        
    }


    /**
     * notice log
     */
    public function returnLog($logType, $xml){
    
        $filedate   = date('Y-m-d');
        $newfile    = fopen(  dirname(dirname(dirname(__FILE__))) . "/oceanpayment_log/" . $filedate . ".log", "a+" );      
        $return_log = date('Y-m-d H:i:s') . $logType . "\r\n";  
        $return_log .= $xml;
        // foreach ($_REQUEST as $k=>$v){
        //     $return_log .= $k . " = " . $v . "\r\n";
        // }   
        $return_log .= '*****************************************' . "\r\n";
        $return_log = $return_log.file_get_contents( dirname(dirname(dirname(__FILE__))) . "/oceanpayment_log/" . $filedate . ".log");     
        $filename   = fopen( dirname(dirname(dirname(__FILE__))) . "/oceanpayment_log/" . $filedate . ".log", "r+" );      
        fwrite($filename,$return_log);
        fclose($filename);
        fclose($newfile);
    
    }

    /**
     *  ???????????????xml
     *
     */
    function xml_parser($str){
        $xml_parser = xml_parser_create();
        if(!xml_parse($xml_parser,$str,true)){
            xml_parser_free($xml_parser);
            return false;
        }else {
            return true;
        }
    }


}


