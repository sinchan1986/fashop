<?php
/**
 * 绑定OR解绑等关联用户业务逻辑处理
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
use fashop\Validate;
use fashop\Db;
use App\Utils\Code;
use App\Utils\Exception;
use fashop\utils\RandomKey;

class Relevance
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
	public function getUsername() : string
	{
		return $this->username;
	}

	/**
	 * @param string $username
	 */
	public function setUsername( string $username ) : void
	{
		$this->username = $username;
	}

	/**
	 * @return string
	 */
	public function getWechatOpenid() : string
	{
		return $this->wechatOpenid;
	}

	/**
	 * @param string $wechatOpenid
	 */
	public function setWechatOpenid( string $wechatOpenid ) : void
	{
		$this->wechatOpenid = $wechatOpenid;
	}

	/**
	 * @return array
	 */
	public function getOptions() : array
	{
		return $this->options;
	}

	/**
	 * @param array $options
	 */
	public function setOptions( array $options ) : void
	{
		$this->options = $options;
	}

	public function __construct( array $options )
	{
		$this->setOptions( $options );
	}

	/**
	 * @return string
	 */
	public function getType() : string
	{
		return $this->type;
	}

	/**
	 * @param string $registerType
	 */
	public function setType( string $registerType ) : void
	{
		$this->type = $type;
	}

	/**
	 * 绑定
	 * @method GET
	 * @return array|null
	 * @throws \App\Utils\Exception
	 */
	public function binding() : ? array
	{
		$this->setType( $this->options['type'] );

		if( isset( $this->options['wechat'] ) ){
			return $this->byWechatOpenid();

		} elseif( isset( $this->options['phone'] ) ){
			return $this->byPassword();

		} elseif( isset( $this->options['wechat_mini'] )  ){
			return $this->byWechatMini();

		} else{
			throw new \App\Utils\Exception( Code::param_error );
		}

	}
    /**
     * 解绑
     * @method GET
     * @return array|null
     * @throws \App\Utils\Exception
     */
    public function untie() : ? array
    {
        $this->setType( $this->options['type'] );

        if( isset( $this->options['wechat'] ) ){
            return $this->byWechatOpenid();

        } elseif( isset( $this->options['phone'] ) ){
            return $this->byPassword();

        } elseif( isset( $this->options['wechat_mini'] )  ){
            return $this->byWechatMini();

        } else{
            throw new \App\Utils\Exception( Code::param_error );
        }

    }

	/**
	 * TODO wechat_openid没有处理
	 * @return mixed
	 * @throws \App\Utils\Exception
	 */
	private function byPassword()
	{
		$this->setUsername( $this->options['username'] );
		$this->setPassword( $this->options['password'] );
		$username                                         = $this->getUsername();
		$userModel                                        = model( 'User' );
		$data['username']                                 = $username;
		$data['password']                                 = UserLogic::encryptPassword( $this->getPassword() );
		$data[$this->getAccountRegisterType( $username )] = $username;

        if( is_numeric( $username ) ){
            $condition['phone']	   = $username;
        }else{
            $condition['username'] = $username;
        }
        $user_model = model('User');
        \App\Model\User::startTransaction();

        $user       = \App\Model\User::init()->getUserInfo($condition, 'id');
        if( $user ){
            throw new \App\Utils\Exception( "user account exist", Code::user_account_exist );
        }

		$data['wechat_openid'] = null;

		if( isset( $this->options['wechat_openid'] ) ){
			$wechat_openid = $this->options['wechat_openid'];
			$this->setWechatOpenid( $wechat_openid );
			$wechat_openid = $this->getWechatOpenid();

			$user_id = Db::name( 'User' )->where( ['wechat_openid' => $wechat_openid] )->value( 'id' );
			if( $user_id > 0 ){
				throw new \App\Utils\Exception( "wechat openid exist", Code::user_wechat_openid_exist );
			}
		}

        $user_id = $userModel->addUser( $data );
		if( !($user_id > 0) ){
            \App\Model\User::rollback();
            return null;
		}

        $profile_data     			 = [];
        $profile_data['user_id']     = $user_id;
        $profile_data['nickname']    = $username;

        $assets_data 			     = [];
        $assets_data['user_id']      = $user_id;

        $user_id = $this->insertUserInfo($user_id, $profile_data, $assets_data, []);

        if($user_id > 0){
            \App\Model\User::commit();
            return ['id' => $user_id];
        }else{
            \App\Model\User::rollback();
            return null;
        }
	}

	/**
	 * @throws \App\Utils\Exception
	 */
	private function byWechatOpenid()
	{
		$wechat_openid = $this->options['wechat_openid'];
		$this->setWechatOpenid( $wechat_openid );
		try{
            $wechatApi                  = new \App\Logic\Wechat\Factory();
            $wechat_user                = $wechatApi->user->get($wechat_openid);

            $condition                  = [];
            $condition['openid'] 		= $wechat_openid;
            $exist_open_id       		= model('UserOpen')->getUserOpenValue($condition, '', 'id');

            if( $exist_open_id > 0 ){
				throw new \App\Utils\Exception( "wechat openid exist", Code::user_wechat_openid_exist );
			} else{
                $data['username'] = "wechat_{$wechat_openid}_" . RandomKey::randMd5(8);

                $user_model       = model('User');
                \App\Model\User::startTransaction();

                $user_id          = \App\Model\User::init()->addUser($data);

                if( !($user_id > 0) ){
                    \App\Model\User::rollback();
                    return null;
                }
                $profile_data     			 = [];
                $profile_data['user_id']     = $user_id;

                $open_data                   = [];
                $open_data['user_id']        = $user_id;
                $open_data['genre']          = 1; //类型 1微信 2小程序 3QQ 4微博....
                $open_data['openid']         = $wechat_openid;
                $open_data['state']          = 0; //是否绑定主帐号 默认0否 1是

                $assets_data 			     = [];
                $assets_data['user_id']      = $user_id;

                if( isset( $this->options['wechat'] ) ){
                    $profile_data['nickname']    = $this->options['wechat']['nickname'];
                    $profile_data['avatar']      = $this->options['wechat']['headimgurl'];
                    $profile_data['sex']         = $this->options['wechat']['sex'] == 1 ? 1 : 0;

                    $open_data['unionid']        = isset( $this->options['wechat']['unionid'] ) ? $this->options['wechat']['unionid'] : null;
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
                } else{
                    $profile_data['nickname']    = $data['username'];

                    $open_data['nickname']       = $data['username'];
                    $open_data['info_aggregate'] = [];
                }

                $user_id = $this->insertUserInfo($user_id, $profile_data, $assets_data, $open_data);
                if($user_id > 0){
                    \App\Model\User::commit();
                    return ['id' => $user_id];
                }else{
                    \App\Model\User::rollback();
                    return null;
                }
			}
		} catch( \Exception $e ){
			throw new $e;
		}
	}

	private function getAccountRegisterType( $username ) : string
	{
		$validate = new Validator();
		if( $validate->is( $username, 'phone' ) === true ){
			return 'phone';
		}
		if( $validate->is( $username, 'email' ) === true ){
			return 'email';
		}
	}

	/**
	 * @throws \App\Utils\Exception
	 */
	private function byWechatMini()
	{

		try{
			$wechat_mini_param = $this->options['wechat_mini_param'];
			$wechatminiApi     = new \App\Logic\Wechatmini\Factory();
			$mini_user         = $wechatminiApi->checkUser( $wechat_mini_param );
			if( is_array( $mini_user ) && array_key_exists( 'openId', $mini_user ) ){

                $condition           = [];
                $condition['openid'] = $mini_user['openId'];
                $exist_open_id       = model('UserOpen')->getUserOpenValue($condition, '', 'id');

				if( $exist_open_id > 0 ){
					throw new \App\Utils\Exception( "wechatmini openid exist", Code::user_wechat_openid_exist );
                } else{
                    $user_model 	  = model('User');
                    \App\Model\User::startTransaction();

                    $data['username'] = "wechat_{$mini_user['openId']}_" . RandomKey::randMd5(8);
                    $user_id          = \App\Model\User::init()->addUser($data);

                    if( !($user_id > 0) ){
                        \App\Model\User::rollback();
                        return null;
                    }

                    $profile_data     			 = [];
                    $profile_data['user_id']     = $user_id;
                    $profile_data['nickname']    = $mini_user['nickName'];
                    $profile_data['avatar']      = $mini_user['avatarUrl'];
                    $profile_data['sex']         = $mini_user['gender'] == 1 ? 1 : 0; //gender 性别 0：未知、1：男、2：女

                    $assets_data 			     = [];
                    $assets_data['user_id']      = $user_id;

                    $open_data                   = [];
                    $open_data['user_id']        = $user_id;
                    $open_data['genre']          = 2; //类型 1微信 2小程序 3QQ 4微博....
                    $open_data['openid']         = $mini_user['openId'];
                    $open_data['unionid']        = isset($mini_user['unionId']) ? $mini_user['unionId'] : null;
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
                    if($user_id > 0){
                        \App\Model\User::commit();
                        return ['id' => $user_id];
					}else{
                        \App\Model\User::rollback();
                        return null;
                    }
				}

			}else{
				return null;
			}
		} catch( \Exception $e ){
			throw new $e;
		}
	}

    /**
	 * 插入用户相关的信息
     * @throws \App\Utils\Exception
     */
    private function insertUserInfo($user_id, $profile_data = [], $assets_data = [], $open_data = [])
    {
        try{
            $user_profile_model = model('UserProfile');
            $user_assets_model  = model('UserAssets');
            $user_open_model    = model('UserOpen');
            $user_alias_model   = model('UserAlias');

			$user_profile_id = \App\Model\UserProfile::insertUserProfile($profile_data);
			if($user_profile_id < 0){
				\App\Model\User::rollback();
				return null;
			}

			$user_assets_id = \App\Model\UserAssets::init()->addUserAssets($assets_data);
			if($user_assets_id < 0){
				\App\Model\User::rollback();
				return null;
			}

			if(isset($open_data)){
                $user_open_id = $user_open_model->addUserOpen($open_data);
                if($user_open_id < 0){
                    \App\Model\User::rollback();
                    return null;
                }

                $alias_data                  = [];
                $alias_data['user_id']       = $user_id;
                $alias_data['alias_user_id'] = $user_id;
                $user_alias_id               = $user_alias_model->addUserAlias($alias_data);
                if($user_alias_id < 0){
                    \App\Model\User::rollback();
                    return null;
                }
            }
        } catch( \Exception $e ){
            \App\Model\User::rollback();
            throw new $e;
        }
    }

}

?>
