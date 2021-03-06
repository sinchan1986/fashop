<?php

namespace App\Validator\Admin;

use ezswoole\Validator;

/**
 * 权限节点验证
 *
 *
 *
 *
 * @copyright  Copyright (c) 2019 MoJiKeJi Inc. (http://www.fashop.cn)
 * @license    http://www.fashop.cn
 * @link       http://www.fashop.cn
 * @since      File available since Release v1.1
 */
class AuthGroupAccess extends Validator
{
	protected $rules
		= [
			'member_ids' => 'require',
			'id'         => 'require',
		];
	protected $message
		= [
			'member_ids.require' => '成员id必须',
			'member_ids.array'   => '成员id格式不对',
			'id.require'         => '组id必填',
		];

	protected $scene
		= [
			'groupMemberEdit' => [
				'member_ids',
				'id',
			],
		];

}