<?php
if (!defined('sugarEntry')) define('sugarEntry', true);
require_once('include/entryPoint.php');
require_once('include/utils/array_utils.php');
if (empty($current_language)) {
    $current_language = $sugar_config['default_language'];
}

$GLOBALS['app_list_strings'] = return_app_list_strings_language($current_language);
$GLOBALS['app_strings'] = return_application_language($current_language);

global $current_user;
$current_user = new User();
$current_user->getSystemUser();

$rlf = new fixLanguageFiles();
$rlf->processLanguageFiles();

//Run a QR&R
require_once('modules/Administration/QuickRepairAndRebuild.php');
$RAC = new RepairAndClear();
$actions = array('clearAll');
$RAC->repairAndClearAll($actions, array('All Modules'), false, $output);

//EOP


class fixLanguageFiles
{
    const TYPE_EMPTY = 0;
    const TYPE_DYNAMIC = 1;
    const TYPE_STATIC = 2;
    const TYPE_UNREADABLE = 3;
    const TYPE_UNWRITABLE = 4;

    const SEV_HIGH = 2;
    const SEV_MEDIUM = 1;
    const SEV_LOW = 0;

    public $customLanguageFileList = array();
    public $customOtherFileList = array();
    public $customListNames = array();
    private $dynamicTokens = array('T_OBJECT_OPERATOR', 'T_DOUBLE_COLON', 'T_CONCAT');
    private $arrayCache = array();
    private $queryCache = array();
    private $globalsFound;
    private $colors;

    public function __construct()
    {
        $this->scanCustomDirectory();
        $this->colors = new Colors();
        if(file_exists('fixLanguageFiles.log')) {
            unlink('fixLanguageFiles.log');
        }
    }

    /**
     *
     */
    public function processLanguageFiles()
    {
        foreach ($this->customLanguageFileList as $fileName) {
            $result = $this->testLanguageFile($fileName);
            switch ($result) {
                case self::TYPE_UNREADABLE:
                    $this->logThis("Unreadable file: {$fileName}",SEV_HIGH);
                    break;
                case self::TYPE_UNWRITABLE:
                    $this->logThis("Unwritable file: {$fileName}",SEV_HIGH);
                    break;
                case self::TYPE_EMPTY:
//                    $this->logThis("Empty language file: {$fileName}");
//                    unlink($fileName);
//                    $this->logThis("-> Deleted file}");
                    break;
                case self::TYPE_DYNAMIC:
                    $this->logThis("You will need to manually update: {$fileName}", SEV_HIGH);
                    break;
                case self::TYPE_STATIC:
                    $this->repairStaticFile($fileName);
                    break;
            }
        }
    }

    /**
     * Tests a PHP file to see if it is a list of static variables or if it has dynamic content in it.
     *
     * Dynamic = $app_list_strings['LBL_EMAIL_ADDRESS_BOOK_TITLE_ICON'] =
     *      SugarThemeRegistry::current()->getImage('icon_email_addressbook',
     *                                              "",
     *                                              null,
     *                                              null,
     *                                              ".gif",
     *                                              'Address Book').' Address Book';
     *
     * Static = $app_list_strings['LBL_EMAIL_ADDRESS_BOOK_TITLE'] = 'Address Book';
     *
     * @param $fileName
     * @return int
     */
    private function testLanguageFile($fileName)
    {
        $varCounter = 0;

        //Check to see if we can process the files at all
        if (!is_readable($fileName)) {
            return self::TYPE_UNREADABLE;
        }
        if (!is_writable($fileName)) {
            return self::TYPE_UNWRITABLE;
        }

        $tokens = token_get_all(file_get_contents($fileName));
        foreach ($tokens as $index => $token) {
            if (is_array($token)) {
                $tokenText = token_name($token[0]);
            } else {
                //this isnt translated for some reason
                if ($tokens[$index] == '.') {
                    $tokenText = 'T_CONCAT';
                } else {
                    $tokenText = "";
                }
            }
            //Check to see if this line contains a variable.  If so
            // then this file isn't empty
            if ($tokenText == 'T_VARIABLE') {
                $varCounter++;
            }
            //Check to see if this line contains one of the
            // dynamic tokens
            if (in_array($token[0], $this->dynamicTokens)) {
                return self::TYPE_DYNAMIC;
            }
        } //end foreach
        //If there were no variables in the file then it is considered empty
        if ($varCounter == 0) {
            return self::TYPE_EMPTY;
        }
        return self::TYPE_STATIC;
    }

    /**
     * @param $fileName
     */
    private function repairStaticFile($fileName)
    {
        global $app_list_strings;
        global $app_strings;

//        $this->logThis("Processing {$fileName}");

        $app_list_strings = array();
        $app_strings = array();
        $count = 0;
        $keyCount = 0;

        //Make a backup of whatever language files are currently loaded
        $temp_app_list_strings = $GLOBALS['app_list_strings'];
        $GLOBALS['app_list_strings'] = array();
        $temp_app_strings = $GLOBALS['app_strings'];
        $GLOBALS['app_strings'] = array();
        $app_strings = array();
        $app_list_strings = array();

        //Process the file to remove $GLOBALS[...]
        $this->globalsFound = false;
        $processedFile = $this->fixGlobals($fileName);
        if (!empty($processedFile)) {
            require($processedFile);
            unlink($processedFile);
        } else {
            require($fileName);
        }
        $changed = $this->globalsFound;

        //See if $app_strings are supposed to be app_list_strings
        foreach ($app_strings as $key => $value) {
            if (is_array($value)) {
                //Should be an app_list_string
                $changed = true;
                $app_list_strings[$key] = $value;
                unset($app_strings[$key]);
            }
        }

        //Now go through and remove the characters [& / - ( )] and spaces (in some cases) from array keys
        $badChars = array(' & ', '&', ' - ', '-', '/', ' / ', '(', ')');
        $goodChars = array('_', '_', '_', '_', '_', '_', '', '');
        foreach ($app_list_strings as $listName => $listValues) {
            foreach ($app_list_strings[$listName] as $oldKey => $sValues) {
                $newKey = str_replace($badChars, $goodChars, $oldKey, $count);
                if ($newKey != $oldKey) {
                    //replace the bad sub-key
                    $keyCount = $keyCount + $count;
                    $changed = true;
                    $app_list_strings[$listName][$newKey] = $sValues;
                    unset($app_list_strings[$listName][$oldKey]);
                    $listField = $this->findListField($listName);
                    $this->updateDatabase($listField, $oldKey, $newKey);
                    $this->updateFieldsMetaDataTable($listField, $newKey, $oldKey);
                    $this->updatefiles($newKey, $oldKey);
                }
            }
        }

        if ($changed) {
            $this->writeLanguageFile($fileName, $app_list_strings, $app_strings, $keyCount);
        } else {
//            $this->logThis("NO CHANGES {$fileName}");
        }

        //Put the language files back
        $GLOBALS['app_list_strings'] = $temp_app_list_strings;
        $GLOBALS['app_strings'] = $temp_app_strings;
    }

    /**
     * Takes a language file and converts any
     *  $GLOBALS['app_list_strings']['whatever']=array (
     * into
     *  $app_list_strings['whatever']=array (
     *
     * @param $fileName
     * @return mixed
     */
    private function fixGlobals($fileName)
    {
        $tmpFileName = "";
        $fileContents = sugar_file_get_contents($fileName);
        $globalsRemoved = preg_replace("/(GLOBALS\[')(\w+)('\])(\[')(\w+)('\])/", "$2$4$5$6", $fileContents, -1, $count);

        if ($count > 0) {
            $this->globalsFound = true;
            $this->logThis("-> {$count} \$GLOBALS removed from {$fileName}");
            $tmpFileName = sys_get_temp_dir() . "/TMP_INCLUDE.php";
            sugar_file_put_contents($tmpFileName, $globalsRemoved, LOCK_EX);
        }
        return $tmpFileName;
    }

    /**
     * @param $fileNameToUpdate
     * @param $app_list_strings
     * @param $app_strings
     */
    private function writeLanguageFile($fileNameToUpdate, $app_list_strings, $app_strings, $keyCount)
    {
        $this->logThis("Updating {$fileNameToUpdate}");
        if ($keyCount > 0) {
            $this->logThis("-> {$keyCount} keys changed");
        }
        $flags = LOCK_EX;
        $moduleList = false;
        $phpTag = "<?php";

        if (count($app_list_strings) > 0) {
            foreach ($app_list_strings as $key => $value) {
                if ($key == 'moduleList' && $moduleList == false) {
                    $the_string = "{$phpTag}\n";
                    foreach ($value as $mKey => $mValue) {
                        $the_string .= "\$app_list_strings['moduleList']['{$mKey}'] = '{$mValue}';\n";
                    }
                    sugar_file_put_contents($fileNameToUpdate, $the_string, $flags);
                    $flags = FILE_APPEND | LOCK_EX;
                    $phpTag = "";
                    $moduleList = true;
                } else {
                    $the_string = "{$phpTag}\n\$app_list_strings['{$key}'] = " .
                        var_export_helper($app_list_strings[$key]) .
                        ";\n";
                    sugar_file_put_contents($fileNameToUpdate, $the_string, $flags);
                    $flags = FILE_APPEND | LOCK_EX;
                    $phpTag = "";
                }
            }
        } else {
            $flags = LOCK_EX;
        }

        if (count($app_strings) > 0) {
            $the_string = "{$phpTag}\n";
            foreach ($app_strings as $key => $value) {
                $the_string .= "\$app_strings['{$key}']='{$value}';\n";
            }

            sugar_file_put_contents($fileNameToUpdate, $the_string, $flags);
        }
        //Make sure the final file is loadable
        // If there is an error this REQUIRE will error out
        require($fileNameToUpdate);
    }

    private function updateFieldsMetaDataTable($listName, $newKey, $oldKey)
    {
        foreach($listName as $moduleName=>$fieldName) {
            $query = str_replace(array("\r", "\n"), "", "UPDATE fields_meta_data
                        SET default_value = REPLACE(default_value, '{$oldKey}', '{$newKey}')
                        WHERE custom_module='{$moduleName}'
                          AND (default_value LIKE '%^{$oldKey}^%' OR default_value = '{$oldKey}')
                          AND ext1='{$fieldName}'");
            $query = preg_replace('/\s+/', ' ', $query);
            //dont bother running the same query twice
            if (!in_array($query, $this->queryCache)) {
                $this->logThis("-> Running Query: {$query}");
                $GLOBALS['db']->query($query, true, "Error updating fields_meta_data.");
                $this->queryCache[] = $query;
            }
        }
    }

    /**
     * @param $listName
     * @param string $module
     * @return array
     */
    private function findListField($listName, $module = "")
    {
        global $beanList;
        $moduleList = array();
        $retArray = array();

        //if the array as already been processed then just return the value
        if (isset($this->arrayCache[$listName])) {
            return $this->arrayCache[$listName];
        }

        if (!empty($module) && array_key_exists($module, $beanList)) {
            $moduleList[$module] = $beanList[$module];
        } else {
            $moduleList = $beanList;
        }
        foreach ($moduleList as $bean => $object) {
            $focus = BeanFactory::getBean($bean);
            if (isset($focus->field_defs) && !empty($focus->field_defs)) {
                foreach ($focus->field_defs as $fieldName => $definitions) {
                    if (array_key_exists('options', $definitions) && $definitions['options'] == $listName) {
                        $retArray[$bean] = $fieldName;
                    }
                }
            }
        }
        if (empty($retArray)) {
            $this->logThis("Could not locate '{$listName}' in bean '{$bean}', it appears not to be used as a dropdown list",SEV_HIGH);

        }
        $this->arrayCache[$listName] = $retArray;
        return $retArray;
    }

    /**
     * @param $fieldData
     * @param $oldValue
     * @param $newValue
     */
    private function updateDatabase($fieldData, $oldValue, $newValue)
    {
        if (!empty($fieldData)) {
            foreach ($fieldData as $module => $fieldName) {
                $bean = BeanFactory::getBean($module);
                $fieldDef = $bean->field_defs[$fieldName];
                if (array_key_exists('source', $fieldDef) && $fieldDef['source'] == 'custom_fields') {
                    $table = $bean->table_name . '_cstm';
                } else {
                    $table = $bean->table_name;
                }
                $query = str_replace(array("\r", "\n"), "", "UPDATE {$table}
                            SET {$fieldName} = REPLACE({$fieldName}, '{$oldValue}', '{$newValue}')
                            WHERE {$fieldName} LIKE '%^{$oldValue}^%' OR
                                  {$fieldName} = '{$oldValue}'");
                $query = preg_replace('/\s+/', ' ', $query);
                //dont bother running the same query twice
                if (!in_array($query, $this->queryCache)) {
                    $this->logThis("-> Running Query: {$query}");
                    $GLOBALS['db']->query($query, true, "Error updating {$table}.");
                    $this->queryCache[] = $query;
                }
            }
        }
    }

    /**
     * Shows a list of files that might need manual updating
     *
     * @param $searchString
     * @param $oldKey
     * @return bool
     */
    private function updateFiles($newKey, $oldKey)
    {
        $matches = array();
        if (empty($newKey) || in_array($oldKey, $this->customListNames)) {
            return false;
        }

        $searchString1 = "'" . $oldKey . "'";
        $searchString2 = '"' . $oldKey . '"';

        foreach ($this->customOtherFileList as $fileName) {
            $text = sugar_file_get_contents($fileName);
            if (strpos($text, $searchString1) !== FALSE) {
                $matches[$fileName] = true;
                $this->customListNames[] = $oldKey;
            } elseif (strpos($text, $searchString2) !== FALSE) {
                $matches[$fileName] = true;
                $this->customListNames[] = $oldKey;
            }
        }

        if (!empty($matches)) {
            $this->logThis("------------------------------------------------------------", SEV_MEDIUM);
            $this->logThis("These files MAY need to be updated to reflect the new key (New '{$newKey}' vs. old '{$oldKey}')", SEV_MEDIUM);
            $this->logThis("-------------------------------------------------------------", SEV_MEDIUM);
            foreach ($matches as $fileName => $flag) {
                $this->logThis("{$fileName}", SEV_MEDIUM);
            }
            $this->logThis("-------------------------------------------------------------", SEV_MEDIUM);
        }
    }

    /**
     * Fills the directory lists so we only have to scan it once.
     *
     * @param string $directory
     */
    private function scanCustomDirectory($directory = 'custom')
    {
        $result = array();
        $path = realpath($directory);

        // Create recursive dir iterator which skips dot folders
        $dir = new RecursiveDirectoryIterator($path,
            FilesystemIterator::SKIP_DOTS);

        // Flatten the recursive iterator, folders come before their files
        $objects = new RecursiveIteratorIterator($dir,
            RecursiveIteratorIterator::SELF_FIRST);

        foreach ($objects as $name => $object) {
            if (!$object->isDir() &&
                stripos($name, '/Language/') !== false &&
                substr($name, -4) == '.php'
            ) {
                $this->customLanguageFileList[] = $name;
            } else if (substr($name, -4) == '.php' ||
                substr($name, -3) == '.js' ||
                substr($name, -4) == '.tpl'
            ) {
                $this->customOtherFileList[] = $name;
            }
        }
    }

    /**
     * flatfile logger
     */
    public function logThis($entry, $severity=SEV_LOW) {
        global $mod_strings;
        if(file_exists('include/utils/sugar_file_utils.php')){
            require_once('include/utils/sugar_file_utils.php');
        }
        $log = 'fixLanguageFiles.log';

        // create if not exists
        $fp = @fopen($log, 'a+');
        if(!is_resource($fp)) {
            $GLOBALS['log']->fatal('fixLanguageFiles could not open/lock upgradeWizard.log file');
            die($mod_strings['ERR_UW_LOG_FILE_UNWRITABLE']);
        }

        $line = date('r') . " [{$severity}] - ".$entry . "\n";

        if(@fwrite($fp, $line) === false) {
            $GLOBALS['log']->fatal('fixLanguageFiles could not write to upgradeWizard.log: '.$entry);
            die($mod_strings['ERR_UW_LOG_FILE_UNWRITABLE']);
        }

        if(is_resource($fp)) {
            fclose($fp);
        }

        switch($severity) {
            case SEV_MEDIUM:
                echo $this->colors->getColoredString($entry,'red',null) . "\n";
                break;
            case SEV_HIGH:
                echo $this->colors->getColoredString($entry,'black','red') . "\n";
                break;
            case SEV_LOW:
            default:
                echo $this->colors->getColoredString($entry,'green',null) . "\n";
                break;
        }

    }
}

class Colors {
    private $foreground_colors = array();
    private $background_colors = array();

    public function __construct() {
        // Set up shell colors
        $this->foreground_colors['black'] = '0;30';
        $this->foreground_colors['dark_gray'] = '1;30';
        $this->foreground_colors['blue'] = '0;34';
        $this->foreground_colors['light_blue'] = '1;34';
        $this->foreground_colors['green'] = '0;32';
        $this->foreground_colors['light_green'] = '1;32';
        $this->foreground_colors['cyan'] = '0;36';
        $this->foreground_colors['light_cyan'] = '1;36';
        $this->foreground_colors['red'] = '0;31';
        $this->foreground_colors['light_red'] = '1;31';
        $this->foreground_colors['purple'] = '0;35';
        $this->foreground_colors['light_purple'] = '1;35';
        $this->foreground_colors['brown'] = '0;33';
        $this->foreground_colors['yellow'] = '1;33';
        $this->foreground_colors['light_gray'] = '0;37';
        $this->foreground_colors['white'] = '1;37';

        $this->background_colors['black'] = '40';
        $this->background_colors['red'] = '41';
        $this->background_colors['green'] = '42';
        $this->background_colors['yellow'] = '43';
        $this->background_colors['blue'] = '44';
        $this->background_colors['magenta'] = '45';
        $this->background_colors['cyan'] = '46';
        $this->background_colors['light_gray'] = '47';
    }

    // Returns colored string
    public function getColoredString($string, $foreground_color = null, $background_color = null) {
        $colored_string = "";

        // Check if given foreground color found
        if (isset($this->foreground_colors[$foreground_color])) {
            $colored_string .= "\033[" . $this->foreground_colors[$foreground_color] . "m";
        }
        // Check if given background color found
        if (isset($this->background_colors[$background_color])) {
            $colored_string .= "\033[" . $this->background_colors[$background_color] . "m";
        }

        // Add string and end coloring
        $colored_string .=  $string . "\033[0m";

        return $colored_string;
    }
}
