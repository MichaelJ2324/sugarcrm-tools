<?php
/*********************************************************************************
 * MD5 File Tester
 * Kenneth Brill (kbrill@sugarcrm.com)
 *
 * This program is free software; you can redistribute it and/or modify it under
 * the terms of the GNU Affero General Public License version 3 as published by the
 * Free Software Foundation with the addition of the following permission added
 * to Section 15 as permitted in Section 7(a): FOR ANY PART OF THE COVERED WORK
 * IN WHICH THE COPYRIGHT IS OWNED BY KENNETH BRILL, KENNETH BRILL DISCLAIMS THE WARRANTY
 * OF NON INFRINGEMENT OF THIRD PARTY RIGHTS.
 *
 * This program is distributed in the hope that it will be useful, but WITHOUT
 * ANY WARRANTY; without even the implied warranty of MERCHANTABILITY or FITNESS
 * FOR A PARTICULAR PURPOSE.  See the GNU Affero General Public License for more
 * details.
 *
 * You should have received a copy of the GNU Affero General Public License along with
 * this program; if not, see http://www.gnu.org/licenses or write to the Free
 * Software Foundation, Inc., 51 Franklin Street, Fifth Floor, Boston, MA
 * 02110-1301 USA.
 *
 * The interactive user interfaces in modified source and object code versions
 * of this program must display Appropriate Legal Notices, as required under
 * Section 5 of the GNU Affero General Public License version 3.
 *
 *
 * @category   Custom Language File
 * @package    MD5FileTester
 * @author     Kenneth Brill <kbrill@sugarcrm.com>
 * @copyright  2011-2013 Kenneth Brill
 * @license    http://www.gnu.org/licenses/agpl.txt
 * @version    1.9
 * @link       http://www.sugarforge.org/reviews/?group_id=1300
 */
$admin_option_defs=array();
$admin_option_defs['Administration']['FILEMD5TEST']= array('Administration','LBL_FILEMD5TEST','LBL_FILEMD5TEST_DESC','./index.php?module=Administration&action=fileMD5Test');
$admin_group_header[]=array('ADMIN_GROUP_HEADER','',false,$admin_option_defs);
