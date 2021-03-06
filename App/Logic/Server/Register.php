<?php
/**
 * 权限业务逻辑处理
 *
 *
 *
 *
 * @copyright  Copyright (c) 2019 WenShuaiKeJi Inc. (http://www.fashop.cn)
 * @license    http://www.fashop.cn
 * @link       http://www.fashop.cn
 * @since      File available since Release v1.1
 */
namespace App\Logic\Server;

use App\Logic\User as UserLogic;
use App\Utils\Code;
use ezswoole\utils\RandomKey;
use ezswoole\Validator;

class Register
{
    /**
     * @var string
     */
    private $username;
    /**
     * @var string
     */
    private $password;
    /**
     * @var string
     */
    private $wechatOpenid;
    /**
     * @var array
     */
    private $options;


    /**
     * @return string
     */
    public function getUsername(): string
    {
        return $this->username;
    }

    /**
     * @param string $username
     */
    public function setUsername(string $username): void
    {
        $this->username = $username;
    }

    /**
     * @return string
     */
    public function getPassword(): string
    {
        return $this->password;
    }

    /**
     * @param string $password
     */
    public function setPassword(string $password): void
    {
        $this->password = $password;
    }

    /**
     * @return string
     */
    public function getWechatOpenid(): string
    {
        return $this->wechatOpenid;
    }

    /**
     * @param string $wechatOpenid
     */
    public function setWechatOpenid(string $wechatOpenid): void
    {
        $this->wechatOpenid = $wechatOpenid;
    }

    /**
     * @return array
     */
    public function getOptions(): array
    {
        return $this->options;
    }

    /**
     * @param array $options
     */
    public function setOptions(array $options): void
    {
        $this->options = $options;
    }

    public function __construct(array $options)
    {
        $this->setOptions($options);
    }

    /**
     * @return string
     */
    public function getRegisterType(): string
    {
        return $this->registerType;
    }

    /**
     * @param string $registerType
     */
    public function setRegisterType(string $registerType): void
    {
        $this->registerType = $registerType;
    }

    /**
     * @return array
     */
    public function getWechatMiniParam(): array
    {
        return $this->WechatMiniParam;
    }

    /**
     * @param array $WechatMiniParam
     */
    public function setWechatMiniParam(array $WechatMiniParam = null): void
    {
        $this->WechatMiniParam = $WechatMiniParam;
    }

    /**
     * @method GET
     * @return array|null
     * @throws \App\Utils\Exception
     */
    public function register(): ? array
    {
        $this->setRegisterType($this->options['register_type']);

        if (isset($this->options['wechat_openid']) && !isset($this->options['username']) && ($this->getRegisterType() == 'wechat_openid' || $this->getRegisterType() == 'wechat_app')) {
            return $this->byWechatOpenid();

        } elseif (isset($this->options['username']) && $this->getRegisterType() == 'password') {
            return $this->byPassword();

        } elseif (isset($this->options['wechat_mini_param']) && $this->getRegisterType() == 'wechat_mini') {
            return $this->byWechatMini();

        } else {
            throw new \App\Utils\Exception(Code::param_error);
        }

    }

    /**
     * TODO wechat_openid没有处理
     * @return mixed
     * @throws \App\Utils\Exception
     */
    private function byPassword()
    {
        $this->setUsername($this->options['username']);
        $this->setPassword($this->options['password']);
        $username                                       = $this->getUsername();
        $data['username']                               = $username;
        $data['password']                               = UserLogic::encryptPassword($this->getPassword());
        $data[$this->getAccountRegisterType($username)] = $username;

        if (is_numeric($username)) {
            $condition['phone'] = $username;
        } else {
            $condition['username'] = $username;
        }
        $user_model = model('User');
        \App\Model\User::startTransaction();

        $user = \App\Model\User::init()->getUserInfo($condition, 'id');
        if ($user) {
            throw new \App\Utils\Exception("user account exist", Code::user_account_exist);
        }

        $data['wechat_openid'] = null;

        if (isset($this->options['wechat_openid'])) {
            $wechat_openid = $this->options['wechat_openid'];
            $this->setWechatOpenid($wechat_openid);
            $wechat_openid = $this->getWechatOpenid();
            $user_id       = model('UserOpen')->getUserOpenValue(['openid' => $wechat_openid], '', 'user_id');
            if ($user_id > 0) {
                throw new \App\Utils\Exception("wechat openid exist", Code::user_wechat_openid_exist);
            }
        }

        $user_id = \App\Model\User::init()->addUser($data);
        if (!($user_id > 0)) {
            \App\Model\User::rollback();
            return null;
        }

        $profile_data             = [];
        $profile_data['user_id']  = $user_id;
        $profile_data['nickname'] = $username;

        $assets_data            = [];
        $assets_data['user_id'] = $user_id;

        $user_id = $this->insertUserInfo($user_id, $profile_data, $assets_data, []);

        if ($user_id > 0) {
            \App\Model\User::commit();
            return ['id' => $user_id];
        } else {
            \App\Model\User::rollback();
            return null;
        }
    }

    /**
     * 公众号和开放平台共用 根据$register_type区分开来
     * @throws \App\Utils\Exception
     */
    private function byWechatOpenid()
    {
        $user_model      = model('User');
        $user_open_model = model('UserOpen');
        $wechat_openid   = $this->options['wechat_openid'];
        $register_type   = $this->options['register_type'];
        $unionid         = $this->options['wechat']['unionid'];

        try {
            switch ($register_type) {
                case 'wechat_openid':
                    $open_id_param = 'open_id';
                    break;
                case 'wechat_app':
                    $open_id_param = 'app_openid';
                    break;
            }
            $exist_open_id = $user_open_model->getUserOpenValue([$open_id_param => $wechat_openid], '', 'id');

            if ($exist_open_id > 0) {
                throw new \App\Utils\Exception("wechat openid exist", Code::user_wechat_openid_exist);
            } else {
                //如果有unionid 则查询有无此unionid用户
                if (isset($unionid)) {
                    $unionid_user_id = $user_open_model->getUserOpenValue(['unionid' => $unionid], '', 'user_id');
                }

                \App\Model\User::startTransaction();

                if ($unionid_user_id > 0) {
                    //修改$unionid对应的用户
                    $update_open_result = $user_open_model->editUserOpen(['unionid' => $unionid], [$open_id_param => $wechat_openid]);

                    if (!$update_open_result) {
                        \App\Model\User::rollback();
                        return null;
                    }
                    \App\Model\User::commit();
                    return ['id' => $unionid_user_id];

                } else {
                    //创建用户
                    $data['username'] = "wechat_{$wechat_openid}_" . RandomKey::randMd5(8);
                    $user_id          = \App\Model\User::init()->addUser($data);

                    if (!($user_id > 0)) {
                        \App\Model\User::rollback();
                        return null;
                    }
                    $profile_data            = [];
                    $profile_data['user_id'] = $user_id;

                    $open_data                   = [];
                    $open_data['user_id']        = $user_id;
                    $open_data['origin_user_id'] = $user_id;
                    $open_data['genre']          = 1; //类型 1微信(app 小程序 公众号 unionid区分) 2QQ 3微博....
                    $open_data[$open_id_param]   = $wechat_openid;
                    $open_data['state']          = 0; //是否绑定主帐号 默认0否 1是

                    $assets_data            = [];
                    $assets_data['user_id'] = $user_id;

                    if (isset($this->options['wechat'])) {
                        $profile_data['nickname'] = $this->options['wechat']['nickname'];
                        $profile_data['avatar']   = $this->options['wechat']['headimgurl'];
                        $profile_data['sex']      = $this->options['wechat']['sex'] == 1 ? 1 : 0;

                        $open_data['unionid']        = $unionid ? $unionid : null;
                        $open_data['nickname']       = $this->options['wechat']['nickname'];
                        $open_data['avatar']         = $this->options['wechat']['headimgurl'];
                        $open_data['sex']            = $this->options['wechat']['sex'] == 1 ? 1 : 0;
                        $open_data['country']        = $this->options['wechat']['country'];
                        $open_data['province']       = $this->options['wechat']['province'];
                        $open_data['city']           = $this->options['wechat']['city'];
                        $open_data['info_aggregate'] = [
                            'nickname' => $open_data['nickname'],
                            'avatar'   => $open_data['avatar'],
                            'sex'      => $open_data['sex'],
                            'province' => $open_data['province'],
                            'city'     => $open_data['city']
                        ];
                    } else {
                        $profile_data['nickname'] = $data['username'];

                        $open_data['nickname']       = $data['username'];
                        $open_data['info_aggregate'] = [];
                    }

                    $user_id = $this->insertUserInfo($user_id, $profile_data, $assets_data, $open_data);
                    if ($user_id > 0) {
                        \App\Model\User::commit();
                        return ['id' => $user_id];
                    } else {
                        \App\Model\User::rollback();
                        return null;
                    }
                }

            }
        } catch (\Exception $e) {
            throw new $e;
        }
    }

    private function getAccountRegisterType($username): string
    {
        $validate = new Validator();
        if ($validate->is($username, 'phone') === true) {
            return 'phone';
        }
        if ($validate->is($username, 'email') === true) {
            return 'email';
        }
    }

    /**
     * @throws \App\Utils\Exception
     */
    private function byWechatMini()
    {
        $user_model      = model('User');
        $user_open_model = model('UserOpen');
        try {
            $wechat_mini_param = $this->options['wechat_mini_param'];
            $wechatminiApi     = new \App\Logic\Wechatmini\Factory();
            $mini_user         = $wechatminiApi->checkUser($wechat_mini_param);
            if (is_array($mini_user) && array_key_exists('openId', $mini_user)) {

                $condition                = [];
                $condition['mini_openid'] = $mini_user['openId'];
                $exist_open_id            = model('UserOpen')->getUserOpenValue($condition, '', 'id');
                if ($exist_open_id > 0) {
                    throw new \App\Utils\Exception("wechatmini openid exist", Code::user_wechat_openid_exist);
                } else {
                    $unionid = $mini_user['unionId'];
                    //如果有unionid 则查询有无此unionid用户
                    if (isset($unionid)) {
                        $unionid_user_id = $user_open_model->getUserOpenValue(['unionid' => $unionid], '', 'user_id');
                    }

                    \App\Model\User::startTransaction();

                    if ($unionid_user_id > 0) {
                        //修改$unionid对应的用户
                        $update_open_result = $user_open_model->editUserOpen(['unionid' => $unionid], ['mini_openid' => $mini_user['openId']]);
                        if (!$update_open_result) {
                            \App\Model\User::rollback();
                            return null;
                        }
                        \App\Model\User::commit();
                        return ['id' => $unionid_user_id];

                    } else {
                        //创建用户
                        $data['username'] = "wechat_mini_{$mini_user['openId']}_" . RandomKey::randMd5(8);
                        $user_id          = \App\Model\User::init()->addUser($data);

                        if (!($user_id > 0)) {
                            \App\Model\User::rollback();
                            return null;
                        }

                        $profile_data             = [];
                        $profile_data['user_id']  = $user_id;
                        $profile_data['nickname'] = $mini_user['nickName'];
                        $profile_data['avatar']   = $mini_user['avatarUrl'];
                        $profile_data['sex']      = $mini_user['gender'] == 1 ? 1 : 0; //gender 性别 0：未知、1：男、2：女

                        $assets_data            = [];
                        $assets_data['user_id'] = $user_id;

                        $open_data                   = [];
                        $open_data['user_id']        = $user_id;
                        $open_data['origin_user_id'] = $user_id;
                        $open_data['genre']          = 1; //类型 1微信(app 小程序 公众号 unionid区分) 2QQ 3微博....
                        $open_data['mini_openid']    = $mini_user['openId'];
                        $open_data['unionid']        = $unionid ? $unionid : null;
                        $open_data['nickname']       = $mini_user['nickName'];
                        $open_data['avatar']         = $mini_user['avatarUrl'];
                        $open_data['sex']            = $mini_user['gender'] == 1 ? 1 : 0; //gender 性别 0：未知、1：男、2：女
                        $open_data['province']       = $mini_user['province'];
                        $open_data['city']           = $mini_user['city'];
                        $open_data['info_aggregate'] = [
                            'nickname' => $open_data['nickname'],
                            'avatar'   => $open_data['avatar'],
                            'sex'      => $open_data['sex'],
                            'province' => $open_data['province'],
                            'city'     => $open_data['city']
                        ];
                        $open_data['state']          = 0; //是否绑定主帐号 默认0否 1是

                        $user_id = $this->insertUserInfo($user_id, $profile_data, $assets_data, $open_data);
                        if ($user_id > 0) {
                            \App\Model\User::commit();
                            return ['id' => $user_id];
                        } else {
                            \App\Model\User::rollback();
                            return null;
                        }
                    }

                }

            } else {
                return null;
            }
        } catch (\Exception $e) {
            throw new $e;
        }
    }

    /**
     * 插入用户相关的信息
     * @throws \App\Utils\Exception
     */
    private function insertUserInfo($user_id, $profile_data = [], $assets_data = [], $open_data = [])
    {
        try {
            $user_profile_model = model('UserProfile');
            $user_assets_model  = model('UserAssets');
            $user_open_model    = model('UserOpen');

            $user_profile_id = \App\Model\UserProfile::insertUserProfile($profile_data);
            if ($user_profile_id < 0) {
                return null;
            }

            $user_assets_id = \App\Model\UserAssets::init()->addUserAssets($assets_data);
            if ($user_assets_id < 0) {
                return null;
            }

            if (isset($open_data)) {
                $user_open_id = $user_open_model->addUserOpen($open_data);
                if ($user_open_id < 0) {
                    return null;
                }
            }
            return $user_id;
        } catch (\Exception $e) {
            throw new $e;
        }
    }

}

?>
