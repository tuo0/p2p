<?php
namespace Common\API;

use Carbon\Carbon;
use Common\Helper\RSA;
use Common\Models\Deposits;
use Common\Models\Merchants;
use Common\Models\PaymentChannelDetail;
use Common\Models\PaymentMethod;
use Illuminate\Http\Request;
use Validator;

class Payment
{
    const PAY_CODE_HTML     = 2;          // 渲染HTML页面
    const PAY_CODE_STRING   = 3;          // 字符串输出
    const PAY_CODE_URL      = 4;          // URL跳转
    const PAY_CODE_QRCODE   = 5;          // 页面渲染二维码

    // 商户信息
    private $merchant       = [];

    // 加密的原始数据
    private $encrypt_data   = '';

    // 解密的数据
    private $decrypt_data   = [];

    // 错误消息
    public $error_message   = '';

    public function __construct(Request $request)
    {
        $validator = $this->_validator('request',$request->all());

        // 验证失败
        if( !$validator['status'] ) {
            throw new Exception($validator['message'],-1);
        };

        // 获取参数
        $this->encrypt_data = $request->get('data');

        // 根据用户商户号获取商户信息
        $merchant = Merchants::select([
            'id',
            'account',
            //'system_public_key',
            //'system_private_key',
            'merchant_public_key',
            'merchant_private_key',
            'md5_key'
        ])
            ->where('account',$request->get('merchant_id'))
            ->first();

        // 商户号不存在
        if( empty($merchant) ) {
            throw new Exception('商户不存在！',-2);
        }

        $this->merchant = $merchant->toArray();

        // 商户使用商户私钥加密数据请求平台接口，平台使用商户公钥解密数据
        $this->decrypt_data = $this->_decrypt($this->encrypt_data);

        // 如果数据解密失败
        if( !$this->decrypt_data ){
            throw new Exception('数据解密失败！',-3);
        }
    }

    /**
     * 支付
     * @return array
     * @throws Exception
     */
    public function pay()
    {
        $validator = $this->_validator('pay',$this->decrypt_data);

        // 验证失败
        if( !$validator['status'] ) {
            $return_data['code']    = -6;
            return [-6,$validator['message']];
        };

        $request_data = [
            // 金额
            'amount'            => $this->decrypt_data['amount'],
            // 异步回调地址
            'callback_url'      => $this->decrypt_data['callback_url'],
            // 同步回调地址
            'callback_url_view' => $this->decrypt_data['callback_url_view'],
            // 商户订单号
            'order_no'          => $this->decrypt_data['order_no'],
            // 商品名
            'goods_name'        => $this->decrypt_data['goods_name'],
            // 支付方式,对应payment_method表ident字段
            'method'            => $this->decrypt_data['method'],
            // 签名
            'sign'              => $this->decrypt_data['sign'],
        ];

        // 如果是在线网银，则需要对应的银行代码
        if( $request_data['method'] == 'netbank' ){
            $request_data['bank_code'] = $this->decrypt_data['bank_code'];
        }

        // 验证MD5签名
        if( !$this->_verify($request_data) ){
            // 签名验证失败
            return [-7,'签名校验失败！'];
        }

        // 检查订单号是否存在
        if( Deposits::where('merchant_order_no',$request_data['order_no'])->count() > 0 ){
            return [-8,'订单号已存在'];
        }

        // 获取支付通道
        $channel_detail = $this->getPaymentChannel( $request_data );
        if( !$channel_detail ){
            // TODO:触发系统告警-运营,将错误消息发送给平台运营人员

            return [-9,$this->error_message];
        }

        // 添加支付订单记录
        $deposits_model = new Deposits();
        $deposits_model->merchant_id = $this->merchant['id'];                               // 商户ID
        $deposits_model->payment_channel_detail_id = $channel_detail['channel_detail_id'];  // 支付通道
        $deposits_model->amount = $request_data['amount'];                                  // 金额
        $deposits_model->merchant_order_no = $request_data['order_no'];                     // 订单号
        $deposits_model->ip = request()->ip();                                              // IP
        $deposits_model->created_at = (string)Carbon::now();                                // 申请时间
        $deposits_model->extra = json_encode([
            'callback_url'      => $request_data['callback_url'],
            'callback_url_view' => $request_data['callback_url_view'],
            'goods_name'        => $request_data['goods_name'],
            'method'            => $request_data['method'],
        ]);

        try {
            // $deposits_model->save();
        } catch (\PDOException $e) {
            \Log::error($e);
            // TODO:触发系统告警-程序
            // 保存数据出错发送警报信息给系统管理员

            return [-10,'数据写入失败！'];
        }

        // 获取支付模型，构建支付数据
        $pay_model = $this->getPaymentModel($channel_detail['category_ident'],[
            // 商户号
            // 秘钥
            // 支付通道ID
            // API网关
        ]);
        if( !$pay_model ){
            return [-11,'模型获取失败！'];
        }

        return $pay_model->prepare_pay([
            // 支付金额
            // 平台订单号
            // 商品名
            // 支付类型
            // 支付银行
            // 客户IP
        ]);
    }

    /**
     * 获取支付通道
     * @param $data
     * @return bool
     */
    private function getPaymentChannel( $data )
    {
        // 获取支付类型，检查支付类型是否状态
        $payment_method = PaymentMethod::select(['id','status'])->where('ident',$data['method'])->first();
        if( empty($payment_method) ){
            $this->error_message = '支付类型不存在！';
            return false;
        }
        if( !$payment_method->status ){
            $this->error_message = '支付类型已关闭！';
            return false;
        }

        $now_time = Carbon::now()->format('H:i:s');

        $details = PaymentChannelDetail::select([
            'payment_channel.id as channel_id',
            'payment_channel.max_amount',
            'payment_channel.channel_param',
            'payment_channel_detail.extra',
            'payment_channel_detail.id as channel_detail_id',
            'payment_category.ident as category_ident'
        ])
            ->leftJoin('payment_channel','payment_channel.id','payment_channel_detail.payment_channel_id')
            ->leftJoin('payment_category','payment_category.id','payment_channel.payment_category_id')
            ->where([
                ['payment_channel_detail.min_amount','<=',$data['amount']],
                ['payment_channel_detail.max_amount','>=',$data['amount']],
                ['payment_channel_detail.payment_method_id','=',$payment_method->id],
                ['payment_channel_detail.status','=',true],
                ['payment_channel_detail.top_merchant_ids','@>',$this->merchant['id']],
                ['payment_channel.status','=',0],
            ])
            ->where( function($query)use($now_time){
                // 正常时间区间
                $query->where([
                    ['payment_channel_detail.start_time','<=',$now_time],
                    ['payment_channel_detail.end_time','>=',$now_time],
                ])
                // 如果跨天，当前时间大于开始时间
                ->orWhereRaw(" ( payment_channel_detail.start_time > payment_channel_detail.end_time AND payment_channel_detail.start_time <= ? ) ",[$now_time])
                // 如果跨天，当前时间小于结束时间
                ->orWhereRaw(" ( payment_channel_detail.start_time > payment_channel_detail.end_time AND payment_channel_detail.end_time >= ? ) ",[$now_time])
                // 或者开始时间和结束时间相同
                ->orWhereRaw("payment_channel_detail.start_time = payment_channel_detail.end_time");
            })
            ->get();

        if( $details->isEmpty() ){
            $this->error_message = '没有符合条件的支付通道！';
            return false;
        }

        if( $details->count() > 1){
            // TODO: 匹配策略
            // TODO：获取通道当天累计充值金额，判断是否超额
            /*
             * 匹配支付通道,匹配规则
             * 1.随机获取支付通道
             * 2.匹配当天充值金额最低的通道
             * 3.优先选择低费率通道
             */

            $detail_data = $details->first()->toArray();
        }else{
            $detail_data = $details->first()->toArray();
        }

        $detail_data['channel_param'] = json_decode($detail_data['channel_param'],true);

        return $detail_data;
    }

    /**
     * 获取支付模型
     * @param $ident
     * @param $channel
     * @return object
     * @throws /Exception
     */
    private function getPaymentModel($ident, $channel)
    {
        $ident = ucfirst($ident);

        $class = "Comment\\API\\Payment\\{$ident}";

        try {
            $pay_model_reflection = new \ReflectionClass($class);

            return $pay_model_reflection->newInstance($channel);
        } catch (Exception $e) {
            \Log::error($e);
            return false;
        }
    }

    /**
     * 签名验证
     * @param array $data 参数
     * @return boolean 验证通过：true 失败：false
     */
    public function _verify( $data )
    {
        if  ($this->_sign( $data) === $data['sign'] ) {
            return true;
        }

        return false;
    }

    /**
     * 签名
     * @param array $data 参数
     * @return boolean 验证通过：true 失败：false
     */
    public function _sign( $data )
    {
        ksort($data);

        $sign_str = '';
        foreach ($data as $k => $v) {
            if ($k !== 'sign') {
                $sign_str .= $k.'='.$v.'&';
            }
        }

        return strtoupper(md5($sign_str . $this->merchant['md5_key']));
    }

    /**
     * 加密参数
     * @param array $data 需要加密的参数
     * @return string
     */
    public function _encrypt( $data )
    {
        return RSA::private_encrypt( json_encode($data) , $this->merchant['merchant_private_key'] );
    }

    /**
     * 解密参数
     * @param string $string 加密的参数
     * @return array
     */
    public function _decrypt( $string )
    {
        $decrypt_string = RSA::public_decrypt( $string , $this->merchant['merchant_public_key'] );
        if( $decrypt_string ){
            $decrypt_string = json_decode($decrypt_string,true);
        }
        return $decrypt_string;
    }

    /**
     * 校验数据
     * @param string $api_name
     * @param string $data
     * @return array [status,message] status:通过为true，失败为false，message：消息
     */
    private function _validator( $api_name , $data )
    {
        $rule       = [];
        $messages   = [];

        // 原始加密请求验证
        if( $api_name == 'request' ){
            // 验证参数
            $rule = [
                'merchant_id'   => 'bail|required|alpha_dash|between:8,16',
                'data'          => 'bail|required|string',
            ];

            $messages = [
                'merchant_id.required'      => '商户号不能为空！',
                'merchant_id.alpha_dash'    => '商户号格式不正确！',
                'merchant_id.between'       => '商户号格式不正确！',
                'data.required'             => '数据不能为空！',
                'data.string'               => '数据格式不正确！',
            ];
            // 解密后的支付请求数据
        }elseif( $api_name == 'pay' ){
            // 验证数据类型是否正确
            $rule = [
                'amount'            => 'bail|required|numeric|min:0.01',
                'callback_url'      => 'bail|required|url|max:255',
                'callback_url_view' => 'bail|required|url|max:255',
                'order_no'          => 'bail|required|string|between:8,32',
                'method'            => 'bail|required|alpha_dash|exists:payment_method,ident',
                'bank_code'         => 'bail|required_if:method,netbank|alpha',
                'sign'              => 'bail|required|alpha_dash',
            ];

            $messages = [
                'amount.required'               => '金额不能为空！',
                'amount.numeric'                => '金额类型不正确！',
                'amount.min'                    => '金额不正确！',
                'callback_url.required'         => '异步回调地址不能不空！',
                'callback_url.url'              => '异步回调地址格式不正确！',
                'callback_url.max'              => '异步回调地址长度不能超过255个字符！',
                'callback_url_view.required'    => '同步回调地址不能不空！',
                'callback_url_view.url'         => '同步回调地址格式不正确！',
                'callback_url_view.max'         => '同步回调地址长度不能超过255个字符！',
                'order_no.required'             => '订单号不能为空！',
                'order_no.string'               => '订单号格式不正确！',
                'order_no.between'              => '订单号长度不正确！',
                'method.required'               => '支付类型不能为空！',
                'method.alpha_dash'             => '支付类型格式不正确！',
                'method.exists'                 => '支付类型不存在！',
                'bank_code.required_if'         => '银行代码不能为空！',
                'bank_code.alpha'               => '银行代码格式不正确！',
                'sign.required'                 => '签名不能为空！',
                'sign.alpha_dash'               => '签名格式不正确！',
            ];
        }elseif( $api_name == 'query' ){

        }

        $validator = Validator::make($data, $rule , $messages);

        $result = [
            'status'    => true,
            'message'   => 'success',
        ];

        if ($validator->fails()) {
            foreach($validator->errors()->all() as $error){
                $result['status']   = false;
                $result['message']  = $error;
                break;
            }
        }

        return $result;
    }
}