<?php 

namespace Oceanpayment\AlipayHK\Controller\Payment; 



use Magento\Framework\App\CsrfAwareActionInterface;
use Magento\Framework\Controller\ResultFactory;
use Magento\Quote\Api\CartManagementInterface;
use Magento\Framework\App\Request\InvalidRequestException;
use Magento\Framework\App\Action\Action;
use Magento\Framework\App\RequestInterface;

class Back extends \Magento\Framework\App\Action\Action implements CsrfAwareActionInterface
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
    protected $urlBuilder;


	
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
        \Magento\Framework\Url $urlBuilder,
        \Magento\Framework\App\Config\ScopeConfigInterface $scopeConfig
    ) {
        $this->_customerSession = $customerSession;
        $this->checkoutSession = $checkoutSession;
        $this->urlBuilder = $urlBuilder;
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
        //????????????
        $model = $this->_paymentMethod;

        if($model->getConfigData('logs')) {
            //??????????????????
            $this->returnLog(self::BrowserReturn, $this->getRequest()->getPost());
        }


        $order = $this->_orderFactory->create()->loadByIncrementId($this->getRequest()->getParam('order_number'));

        $history = ' (payment_id:'.$this->getRequest()->getParam('payment_id').' | order_number:'.$this->getRequest()->getParam('order_number').' | '.$this->getRequest()->getParam('order_currency').':'.$this->getRequest()->getParam('order_amount').' | payment_details:'.$this->getRequest()->getParam('payment_details').')';

        switch($this->validated($order)){
            case 1:
                //????????????
                $order->setState($model->getConfigData('success_order_status'));
                $order->setStatus($model->getConfigData('success_order_status'));
                $order->addStatusToHistory($model->getConfigData('success_order_status'), __(self::BrowserReturn.'Payment Success!'.$history));
                
                //????????????
                // $this->orderSender->send($order);
                
                //??????Invoice
                if ($model->getConfigData('invoice')){
                    $this->_createInvoice($order);
                }

                $order->save();

                $url = 'checkout/onepage/success';
                break;
            case 0:
                //????????????
                $order->setState($model->getConfigData('failure_order_status'));
                $order->setStatus($model->getConfigData('failure_order_status'));
                $order->addStatusToHistory($model->getConfigData('failure_order_status'), __(self::BrowserReturn.'Payment Failed!'.$history));
                $order->save();

                $this->messageManager->addError(__('Payment Failed! '.$this->getRequest()->getParam('payment_details')));
                $url = 'checkout/onepage/failure';
                break;
            case -1:
                //???????????????
                $order->setState($model->getConfigData('pre_auth_order_status'));
                $order->setStatus($model->getConfigData('pre_auth_order_status'));
                $order->addStatusToHistory($model->getConfigData('pre_auth_order_status'), __(self::BrowserReturn.'(Pre-auth)Payment Pending!'.$history));
                $order->save();

                $url = 'checkout/onepage/success';
                break;
            case 2:
                //?????????????????????????????????
                $url = 'checkout/onepage/success';  
                break;  
            case '10000':
                //10000:Payment is declined ???????????????
                $order->setState($model->getConfigData('high_risk_order_status'));
                $order->setStatus($model->getConfigData('high_risk_order_status'));
                $order->addStatusToHistory($model->getConfigData('high_risk_order_status'), __(self::BrowserReturn.'(High Risk)Payment Failed!'.$history));
                $order->save();

                $this->messageManager->addError(__('Payment Failed! '.$this->getRequest()->getParam('payment_details')));
                $url = 'checkout/onepage/failure';
                break;
            case '20061':
                //???????????????
                $url = 'checkout/onepage/failure';
                break;
            case 999:
                //??????????????????????????????
                $url = 'checkout/onepage/failure';
                break;
            default:

        }


        $url = $this->urlBuilder->getUrl($url);
        $this->_redirect($url);
//        $this->getParentLocationReplace($url);

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
        
        //??????????????????????????????
//        $_SESSION['payment_details'] = $payment_details;
        
        //??????????????????????????????????????????
        $getErrorCode                = explode(':', $payment_details);  
//        $_SESSION['errorCode']       = $getErrorCode[0];
        
        //??????????????????
//        $_SESSION['payment_solutions']= $_REQUEST['payment_solutions'];
        
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
            //?????????????????????????????????
            if(in_array($order->getState(), $this->_processingArray)){
                return 2;
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
     * return log
     */
    public function returnLog($logType, $data){
    
        $filedate   = date('Y-m-d');
        $newfile    = fopen(  dirname(dirname(dirname(__FILE__))) . "/oceanpayment_log/" . $filedate . ".log", "a+" );      
        $return_log = date('Y-m-d H:i:s') . $logType . "\r\n";  
        foreach ($data as $k=>$v){
            $return_log .= $k . " = " . $v . "\r\n";
        }   
        $return_log .= '*****************************************' . "\r\n";
        $return_log = $return_log.file_get_contents( dirname(dirname(dirname(__FILE__))) . "/oceanpayment_log/" . $filedate . ".log");     
        $filename   = fopen( dirname(dirname(dirname(__FILE__))) . "/oceanpayment_log/" . $filedate . ".log", "r+" );      
        fwrite($filename,$return_log);
        fclose($filename);
        fclose($newfile);
    
    }


    /**
     *  JS 
     *
     */
    public function getParentLocationReplace($url)
    {
        return '<script type="text/javascript">parent.location.replace("'.$url.'");</script>';
    }

    public function createCsrfValidationException(RequestInterface $request): ?InvalidRequestException
    {
        return null;
    }

    public function validateForCsrf(RequestInterface $request): ?bool
    {
        return true;
    }

}


