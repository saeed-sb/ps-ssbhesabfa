<?php
/**
 * 2007-2020 PrestaShop
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Academic Free License (AFL 3.0)
 * that is bundled with this package in the file LICENSE.txt.
 * It is also available through the world-wide-web at this URL:
 * http://opensource.org/licenses/afl-3.0.php
 * If you did not receive a copy of the license and are unable to
 * obtain it through the world-wide-web, please send an email
 * to license@prestashop.com so we can send you a copy immediately.
 *
 * DISCLAIMER
 *
 * Do not edit or add to this file if you wish to upgrade PrestaShop to newer
 * versions in the future. If you wish to customize PrestaShop for your
 * needs please refer to http://www.prestashop.com for more information.
 *
 *  @author    PrestaShop SA <contact@prestashop.com>
 *  @copyright 2007-2020 PrestaShop SA
 *  @license   http://opensource.org/licenses/afl-3.0.php  Academic Free License (AFL 3.0)
 *  International Registered Trademark & Property of PrestaShop SA
 */

class HesabfaModel extends ObjectModel
{
    public static $definition = [
        'table' => 'ssb_hesabfa',
        'primary' => 'id_ssb_hesabfa',
        'multilang' => false,
        'fields' => [
            'id_ssb_hesabfa' => ['type' => self::TYPE_INT, 'validate' => 'isUnsignedInt'],
            'obj_type' => ['type' => self::TYPE_STRING, 'db_type' => 'varchar(32)'],
            'id_hesabfa' => ['type' => self::TYPE_INT, 'db_type' => 'int(10)', 'validate' => 'isUnsignedInt'],
            'id_ps' => ['type' => self::TYPE_INT, 'db_type' => 'int(10)', 'validate' => 'isUnsignedInt'],
        ],
    ];

    public $id_ssb_hesabfa;
    public $obj_type;
    public $id_hesabfa;
    public $id_ps;
}
