<?php

namespace App\Http\Controllers;

use Common\Models\AgentUsers;
use Common\Models\PaymentChannelDetail;
use Common\Models\PaymentMethod;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AgentUsersController extends Controller
{
    /**
     * Create a new controller instance.
     *
     * @return void
     */
    public function __construct()
    {
        $this->middleware('auth');
    }

    public function postIndex(Request $request)
    {
        $page  = (int)$request->get('page', 1);
        $limit = (int)$request->get('limit');

        $start = ($page - 1) * $limit;

        $data = [
            'total'       => 0,
            'users_list' => [],
        ];

        $data['agent_users_list'] = AgentUsers::select([
            'agent_users.id',
            'agent_users.username',
            'agent_users.nickname',
            'agent_users.status',
            'agent_users.last_ip',
            'agent_users.last_time',
            'agent_users.created_at'
        ])
            ->orderBy('id', 'asc')
            ->skip($start)
            ->take($limit)
            ->get()
            ->toArray();

        $data['total'] = AgentUsers::count();

        $data['payment_method'] = PaymentMethod::select([
            'id',
            'ident',
            'name',
        ])
            ->where('status','=',true)
            ->get()
            ->toArray();

        // 计算最小返点
        foreach($data['payment_method'] as &$payment_method){
            $min_rate = $this->_getMinRate( $payment_method['id'] );
            if( $min_rate !== false ){
                $payment_method['min_rate'] = $min_rate;
            }else{
                unset($payment_method);
            }
        }

        // 重建索引
        $data['payment_method'] = array_merge($data['payment_method']);

        // 获取返点限制
        $data['rebates_limit'] = [
            'withdrawal_rebate'      => (float)getSysConfig('rebates_withdrawal_rebate',0),
            'user_deposit_rebate'    => (float)getSysConfig('rebates_user_deposit_rebate',0),
            'user_withdrawal_rebate' => (float)getSysConfig('rebates_user_withdrawal_rebate',0),
        ];


        return $this->response(1, 'Success!', $data);
    }

    public function postCreate(Request $request)
    {
        $users                  = new AgentUsers();
        $users->username        = $request->get('username','');
        $users->nickname        = $request->get('nickname','');
        $users->password        = $request->get('password','');
        //$users->user_group_id   = $request->get('user_group_id');
        $users->status          = (int)$request->get('status',0)?true:false;

        $request_rebates        = $request->get('rebates');

        $rebates = [
            'deposit_rebates'       => [],
            'withdrawal_rebate'     => [],
            'user_deposit_rebate'   => [],
            'user_withdrawal_rebate'=> [],
        ];
        if( !empty($request_rebates) ){
            // 判断返代收点是否合法
            if( !empty($request_rebates['deposit_rebates']) ){
                $rebates['deposit_rebates'] = [];
                foreach($request_rebates['deposit_rebates'] as $rebate){
                    if( !$rebate['status'] ) continue;
                    $min_rate = $this->_getMinRate( $rebate['id'] );
                    if( $min_rate !== false && $rebate['rate'] >= $min_rate){
                        $rebates['deposit_rebates'][$rebate['id']] = [
                            'payment_method_id' => $rebate['id'],
                            'rate'              => $rebate['rate'],
                            'status'            => $rebate['status']
                        ];
                    }
                }
            }

            // 判断代付返点是否合法
            $withdrawal_rate = getSysConfig('rebates_withdrawal_rebate',0);
            if( !empty($request_rebates['withdrawal_rebate']) &&
                isset($request_rebates['withdrawal_rebate']['status']) && isset($request_rebates['withdrawal_rebate']['amount']) &&
                $request_rebates['withdrawal_rebate']['amount'] >= $withdrawal_rate){
                $rebates['withdrawal_rebate'] = [
                    'status'    => $request_rebates['withdrawal_rebate']['status'],
                    'amount'    => $request_rebates['withdrawal_rebate']['amount'],
                ];
            }

            // 判断散户代收佣金是否合法
            $user_deposit_rebate = getSysConfig('rebates_user_deposit_rebate',0);
            if( !empty($request_rebates['user_deposit_rebate']) &&
                isset($request_rebates['user_deposit_rebate']['status']) && isset($request_rebates['user_deposit_rebate']['rate']) &&
                $request_rebates['user_deposit_rebate']['rate'] <= $user_deposit_rebate){
                $rebates['user_deposit_rebate'] = [
                    'status'    => $request_rebates['user_deposit_rebate']['status'],
                    'rate'    => $request_rebates['user_deposit_rebate']['rate'],
                ];
            }

            // 判断散户代付佣金是否合法
            $user_withdrawal_rebate = getSysConfig('rebates_user_withdrawal_rebate',0);
            if( !empty($request_rebates['user_withdrawal_rebate']) &&
                isset($request_rebates['user_withdrawal_rebate']['status']) && isset($request_rebates['user_withdrawal_rebate']['amount']) &&
                $request_rebates['user_withdrawal_rebate']['amount'] <= $user_withdrawal_rebate){
                $rebates['user_withdrawal_rebate'] = [
                    'status'    => $request_rebates['user_withdrawal_rebate']['status'],
                    'amount'    => $request_rebates['user_withdrawal_rebate']['amount'],
                ];
            }
        }

        $users->extra = json_encode([
            'rebates'   => $rebates,
        ]);

        if( $users->save() ){
            return $this->response(1, '添加成功');
        } else {
            return $this->response(0, '添加失败');
        }
    }

    public function getEdit(Request $request)
    {
        $id = (int)$request->get('id');

        $users = AgentUsers::find($id);

        if (empty($users)) {
            return $this->response(0, '代理不存在');
        }

        $users = $users->toArray();
        $extra = isset($users['extra']) ? json_decode($users['extra'],true) : [];
        $users['rebates'] = $extra['rebates'];
//        if( !empty($extra['rebates']) ){
//            foreach($extra['rebates'] as $rebate){
//                $users['rebates'][$rebate['payment_method_id']] = $rebate;
//            }
//        }

        return $this->response(1, 'success', $users);
    }

    public function putEdit(Request $request)
    {
        $id = (int)$request->get('id');

        $users = AgentUsers::find($id);
        $extra = json_decode($users->extra,true);

        if (empty($users)) {
            return $this->response(0, '代理不存在');
        }

        $users->nickname        = $request->get('nickname','');
        $users->status          = (int)$request->get('status',0)?true:false;


        $password        = $request->get('password','');
        if( !empty($password) ){
            $users->password = bcrypt($password);
        }

        $request_rebates        = $request->get('rebates');
        $rebates = [
            'deposit_rebates'       => [],
            'withdrawal_rebate'     => [],
            'user_deposit_rebate'   => [],
            'user_withdrawal_rebate'=> [],
        ];
        if( !empty($request_rebates) ){
            // 判断返点是否合法
            // 判断返代收点是否合法
            if( !empty($request_rebates['deposit_rebates']) ){
                foreach($request_rebates['deposit_rebates'] as $rebate){
                    if( !isset($extra['rebates']) &&
                        !isset($extra['rebates'][$rebate['payment_method_id']]) &&
                        $rebate['rate'] == 0 ){
                        continue;
                    }

                    $min_rate = $this->_getMinRate( $rebate['id'] );
                    if( $min_rate !== false && $rebate['rate'] < $min_rate){
                        return $this->response(0, $rebate['name'].'费率不能低于系统最低费率！');
                    }

                    // TODO：检查是否有上级，检查上级返点

                    // TODO: 检查下级


                    $rebates['deposit_rebates'][$rebate['id']] = [
                        'payment_method_id' => $rebate['id'],
                        'rate'              => $rebate['rate'],
                        'status'            => $rebate['status']
                    ];
                }
            }

            // 判断代付返点是否合法
            $withdrawal_rate = getSysConfig('rebates_withdrawal_rebate',0);

            if( !empty($request_rebates['withdrawal_rebate']) &&
                isset($request_rebates['withdrawal_rebate']['status']) && isset($request_rebates['withdrawal_rebate']['amount'])
                ){
                if( $request_rebates['withdrawal_rebate']['amount'] < $withdrawal_rate ){
                    return $this->response(0, '代付返点配置错误！');
                }

                //TODO:获取下级返点，检查是否高于下级返点

                $rebates['withdrawal_rebate'] = [
                    'status'    => $request_rebates['withdrawal_rebate']['status'],
                    'amount'    => $request_rebates['withdrawal_rebate']['amount'],
                ];
            }

            // 判断散户代收佣金是否合法
            $user_deposit_rebate = getSysConfig('rebates_user_deposit_rebate',0);
            if( !empty($request_rebates['user_deposit_rebate']) &&
                isset($request_rebates['user_deposit_rebate']['status']) && isset($request_rebates['user_deposit_rebate']['rate'])
                ){
                if( $request_rebates['user_deposit_rebate']['rate'] > $user_deposit_rebate ){
                    return $this->response(0, '散户代收佣金配置错误！');
                }

                //TODO:获取下级返点，检查是否高于下级返点

                $rebates['user_deposit_rebate'] = [
                    'status'    => $request_rebates['user_deposit_rebate']['status'],
                    'rate'    => $request_rebates['user_deposit_rebate']['rate'],
                ];
            }

            // 判断散户代付佣金是否合法
            $user_withdrawal_rebate = getSysConfig('rebates_user_withdrawal_rebate',0);
            if( !empty($request_rebates['user_withdrawal_rebate']) &&
                isset($request_rebates['user_withdrawal_rebate']['status']) && isset($request_rebates['user_withdrawal_rebate']['amount'])
                ){
                if( $request_rebates['user_withdrawal_rebate']['amount'] > $user_withdrawal_rebate ){
                    return $this->response(0, '散户代付佣金配置错误！');
                }

                //TODO:获取下级返点，检查是否高于下级返点

                $rebates['user_withdrawal_rebate'] = [
                    'status'    => $request_rebates['user_withdrawal_rebate']['status'],
                    'amount'    => $request_rebates['user_withdrawal_rebate']['amount'],
                ];
            }
        }

        $extra['rebates'] = $rebates;
        $users->extra = json_encode($extra);

        if ($users->save()) {
            return $this->response(1, '编辑成功');
        } else {
            return $this->response(0, '编辑失败');
        }
    }

    public function deleteDelete(Request $request)
    {
        $id = $request->get('id');
        if( AgentUsers::where('id','=',$id)->delete() ){
            return $this->response(1,'删除成功！');
        }else{
            return $this->response(0,'删除失败！');
        }
    }

    /**
     * 获取通道最低返点
     * @param $payment_method_id
     * @return false|string
     *
     */
    private function _getMinRate( $payment_method_id )
    {
        $detail_model = PaymentChannelDetail::select([
            DB::raw('max(rate) as max_rate'),
        ])
            ->where([
                //['status','=',true],
                ['payment_method_id','=',$payment_method_id]
            ])
            ->groupBy('payment_method_id')
            ->first();

        if( !empty($detail_model) ){
            $platform_min_rate = getSysConfig('rebates_deposit_platform_min_rate',0);
            return $detail_model->max_rate + $platform_min_rate;
        }else{
            return false;
        }
    }
}
