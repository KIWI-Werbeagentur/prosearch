<?php namespace ProSearch;

/**
 * Contao Open Source CMS
 *
 * Copyright (c) 2005-2015 Leo Feyer
 *
 * @package   Pro Search
 * @author    Alexander Naumov http://www.alexandernaumov.de
 * @license   commercial
 * @copyright 2015 Alexander Naumov
 */


use Contao\Backend;
use Contao\Config;
use Contao\Input;
use Contao\Controller;


/**
 * Class ProSearch
 */
class ProSearch extends Backend
{

    public $coreModules = array();
    public $deletedIndexData = array();
    public $modules = array(

        'article' => array(
            'shortcut' => 'ar',
            'tables' => array('tl_article')
        ),

        'page' => array(
            'shortcut' => 'pa',
            'tables' => array('tl_page')
        ),

        'form' => array(
            'shortcut' => 'fo',
            'tables' => array('tl_form')
        ),

        'member' => array(
            'shortcut' => 'me',
            'tables' => array('tl_member')
        ),

        'user' => array(
            'shortcut' => 'us',
            'tables' => array('tl_user')
        ),

        'news' => array(
            'shortcut' => 'ne',
            'tables' => array('tl_news_archive', 'tl_news')
        ),

        'calendar' => array(
            'shortcut' => 'ev',
            'tables' => array('tl_calendar', 'tl_calendar_events')
        ),

        'files' => array(
            'shortcut' => 'fi',
            'tables' => array('tl_files')
        ),

        'comments' => array(
            'shortcut' => 'co',
            'tables' => array('tl_comments')
        ),

        'newsletter' => array(
            'shortcut' => 'nl',
            'tables' => array('tl_newsletter')
        ),

        'faq' => array(
            'shortcut' => 'fq',
            'tables' => array('tl_faq_category', 'tl_faq')
        ),

        'content' => array(
            'shortcut' => 'ce',
            'tables' => array('tl_content')
        ),

        'module' => array(
            'shortcut' => 'fe',
            'tables' => array('tl_module')
        )

    );

    /**
     * @return array
     * load all searchable modules
     */
    public function loadModules()
    {

        $return = array();

        // set core modules
        $coreModules = $this->coreModules;

        // push dca' into $searchDataContainerArr
        foreach ($coreModules as $coreModule) {

            // set tablename
            $tablename = $coreModule;

            // load dca
            $this->loadDataContainer($tablename);

            // break up if dca not exist
            if (!$GLOBALS['TL_DCA'][$tablename]) {
                continue;
            }

            $return[] = $tablename;

        }

        return $return;
    }

    public function __construct()
    {
        parent::__construct();
        $this->setCoreModules();

    }

    public function setCoreModules()
    {
        foreach($this->modules as $module)
        {
            foreach($module['tables'] as $table)
            {
                $this->coreModules[] = $table;
            }
        }
    }

    /**
     *
     */
    public function deleteModulesFromIndex()
    {
        $activeModules = deserialize(Config::get('searchIndexModules')) ? deserialize(Config::get('searchIndexModules')) : array();

        $toDeleteArr = array_diff($this->coreModules, $activeModules);

        $whereStr = 'WHERE dca = "'.$toDeleteArr[0].'"';

        if(count($toDeleteArr) > 1)
        {
            foreach($toDeleteArr as $key => $value)
            {
                if($key == 0)
                {
                    continue;
                }

                $whereStr .= ' OR dca = "'.$value.'"';
            }
        }

        $this->Database->prepare('DELETE FROM tl_prosearch_data '.$whereStr.'')->execute();

    }

    /**
     * @param $strName
     */
    public function createOnSubmitCallback($strName)
    {
        if( in_array( $strName, $this->coreModules ) && $GLOBALS['TL_DCA'][$strName] )
        {
            $GLOBALS['TL_DCA'][$strName]['config']['onsubmit_callback'][] = array('ProSearch', 'sendDataToIndex');
            $GLOBALS['TL_DCA'][$strName]['config']['oncut_callback'][] = array('ProSearch', 'sendDataToIndex');
            $GLOBALS['TL_DCA'][$strName]['config']['ondelete_callback'][] = array('ProSearch', 'deleteDataFromIndex');
        }
    }

    /**
     * @param $dc
     */
    public function sendDataToIndex($dc)
    {
        // current table
        $tablename = $dc->table;

        // current data
        $dcaArr = $dc->activeRecord ? $dc->activeRecord->row() : array();

        // get act
        $act = Input::get('act');

        // if cut
        if($act && $act == 'cut')
        {
            $id = Input::get('id');
            $dcaArr = $this->Database->prepare('SELECT * FROM '.$tablename.' WHERE id = ?')->execute($id)->row();

        }

        //
        $arr = array();
        $data = $this->prepareIndexData($dcaArr, $GLOBALS['TL_DCA'][$tablename], $tablename);
        if($data == false)
        {
            return;
        }
        $arr[] = $data;

        //
        $searchDataDB = $this->Database->prepare('SELECT * FROM tl_prosearch_data WHERE dca = ? AND docId = ?')->execute($tablename, $dcaArr['id']);
        $newIndexData = $this->fillNewIndexWithExistData($searchDataDB, $arr);

        //save data
        $this->saveSingleIndexIntoDB($newIndexData, $tablename);


    }

    /**
     *
     */
    public function getDo($stable)
    {
       foreach($this->modules as $do => $module)
       {
           foreach($module['tables'] as $table)
           {
               if($stable == $table)
               {
                   return $do;
               }
           }
       }

       return '';
    }

    /**
     * @param $db
     * @param $dca
     * @param $do
     * @return array|bool
     */
    public function prepareIndexData($db, $dca, $table)
    {

        // create do string
        $doStr = $this->getDo($table);
        $doTable = $doStr != '' ? $doStr : '';

        // break up if data has no id
        if( !$db['id'] || !$table || $doTable == '')
        {
            return false;
        }

        // set config
        $arr = array(
            'dca' => $table,
            'tstamp' => time(),
            'doTable' => $doTable,
            'ptable' => $dca['config']['ptable'] ? $dca['config']['ptable'] : '',
            'ctable' => $dca['config']['ctable'] ? serialize($dca['config']['ctable']) : '',
            'docId' => $db['id'],
            'pid' => $db['pid'] ? $db['pid'] : '',
            'shortcut' => $this->modules[$doTable] ? $this->modules[$doTable]['shortcut'] : ''
        );

        // exception for tl_content
        if( $table == 'tl_content' && $arr['ptable'] == '')
        {
            $arr['ptable'] = $db['ptable'] ? $db['ptable'] : '';
        }

        // exception for tl_page
        if( $table == 'tl_page' && $arr['ptable'] == '')
        {
            $arr['ptable'] = 'tl_page';
        }

        // exception for tl_file
        if( $table == 'tl_files' )
        {
            $arr['docId'] = $db['path'] ? $db['path'] : '';
            $arr['pid'] = '';
        }

        // set operations
        $arr = $this->pluckOperations($dca, $arr);

        // set type
        $arr['type'] = $this->setType($db) ? $this->setType($db) : '';

        // set search content
        $arr['search_content'] = $this->setSearchContent($db) ? $this->setSearchContent($db) : '';

        // set title
        $arr['title'] = $this->setTitle($db) ? $this->setTitle($db) : 'no title';

        return $arr;
    }

    /**
     * @param $db
     * @return string|void
     */
    public function setType($db)
    {
        $colsForTypes = array('type');

        foreach($colsForTypes as $type)
        {
            if( $db[$type] && is_string($db[$type]) && $db[$type] != '' )
            {
                $meta = Helper::parseStrForMeta($db[$type]);
                $return = $meta != '' ? ' '.$meta : ' '.$db[$type];
                $return = Controller::replaceInsertTags($return);
                $return = strip_tags($return);
                $return = trim($return);
                return $return;
                break;

            }
        }

        return;

    }

    /**
     * @param $db
     * @return string|void
     */
    public function setSearchContent($db)
    {

        $title = $this->setTitle($db);

        $colsSearchContent = array('ps_description', 'text', 'teaser', 'description', 'content', 'meta');

        $strContent = $title;

        foreach($colsSearchContent as $content)
        {
            if( $db[$content] && is_string($db[$content]) && $db[$content] != '' )
            {
                $meta = Helper::parseStrForMeta($db[$content]);
                $strContent .= $meta != '' ? ' '.$meta : ' '.$db[$content];
            }
        }

        $strContent = Controller::replaceInsertTags($strContent);
        $strContent = strip_tags($strContent);
        $strContent = trim($strContent);

        return $strContent;

    }

    /**
     * @param $db
     */
    public function setTitle($db)
    {
        // sorted by priority
        $colsForTitle = array('ps_title', 'title', 'name', 'headline', 'alias', 'username');

        foreach($colsForTitle as $title)
        {
            if( $db[$title] && is_string($db[$title]) && $db[$title] != '' )
            {
                $meta = Helper::parseStrForMeta($db[$title]);

                $return = $meta != '' ? ' '.$meta : ' '.$db[$title];
                $return = Controller::replaceInsertTags($return);
                $return = strip_tags($return);
                $return = trim($return);

                return $return;
                break;
            }
        }

        return;
    }

    /**
     * @param $dca
     * @param $arr
     * @return array
     */
    public function pluckOperations($dca, $arr)
    {
        //
        $operations = $dca['list']['operations'];

        //
        $allowedOps = array(
            'cmdEdit' => '0',
            'cmdDelete' => '0',
            'cmdPaste' => '0',
            'cmdShow' => '0',
            'cmdAjaxPublished' => '0',
        );

        //
        if(is_array($operations) && !empty($operations))
        {
            // @todo cmdAjaxEdit // cmdCreate
            foreach($operations as $act => $operation)
            {

                // edit
                if( $act == 'edit')
                {
                    $allowedOps['cmdEdit'] = '1';
                }

                //delete
                if( $act == 'delete')
                {
                    $allowedOps['cmdDelete'] = '1';
                }

                //past
                if( $act == 'copy')
                {
                    $allowedOps['cmdPaste'] = '1';
                }

                //show
                if( $act == 'show')
                {
                    $allowedOps['cmdShow'] = '1';
                }

                //toggle
                if( $act == 'toggle')
                {
                    $allowedOps['cmdAjaxPublished'] = '1';
                }
            }
        }

        return array_merge($arr, $allowedOps);

    }

    /**
     * @param $searchDataDB
     * @param $arr
     * @return array
     */
    public function fillNewIndexWithExistData($searchDataDB, $arr)
    {
        if($searchDataDB->count() == 0)
        {
            return $arr;
        }

        while($searchDataDB->next())
        {
            for($i = 0; $i < count($arr); $i++)
            {
                // defaut
                if( $searchDataDB->docId == $arr[$i]['docId'] && $searchDataDB->doTable == $arr[$i]['doTable'] )
                {
                    $arr[$i]['id'] = $searchDataDB->id;
                    $arr[$i]['tags'] = $searchDataDB->tags;
                    $arr[$i]['clicks'] = $searchDataDB->clicks;
                }

            }
        }

        return $arr;
    }

    /**
     * @param $indexData
     */
    public function saveIndexDataIntoDB($data, $dca)
    {
        //reset table
        $this->Database->prepare('DELETE FROM tl_prosearch_data WHERE dca = ?')->execute($dca);

        // insert new cols
        foreach($data as $arr)
        {

            // values
            $values = array_values($arr);
            $placeholder = implode(',', array_fill(0,count($values), '?'));

            // cols
            $cols = array_keys($arr);
            $cols = implode(',',$cols);

            // db operations
            $this->Database->prepare('INSERT INTO tl_prosearch_data('.$cols.') VALUES ('.$placeholder.')')->execute($values);

        }
    }

    /**
     * @param $data
     * @param $dca
     */
    public function saveSingleIndexIntoDB($data, $dca)
    {

        foreach($data as $arr)
        {

            if( !$arr['docId'] || !$arr['docId'] != '' )
            {
                continue;
            }

            $this->Database->prepare('DELETE FROM tl_prosearch_data WHERE dca = ? AND docId = ?')->execute($dca, $arr['docId']);

            // values
            $values = array_values($arr);
            $placeholder = implode(',', array_fill(0,count($values), '?'));

            // cols
            $cols = array_keys($arr);
            $cols = implode(',',$cols);

            $this->Database->prepare('INSERT INTO tl_prosearch_data('.$cols.') VALUES ('.$placeholder.')')->execute($values);

        }

    }

    /**
     * @param $dca
     */
    public function deleteDataFromIndex($dc)
    {

        $tablename = $dc->table;
        $docId = $dc->activeRecord->id;

        // files exception
        if(Input::get('do') == 'files')
        {
            $tablename = 'tl_files';
            $docId = $dc;
        }

        if( !$tablename )
        {
            return;
        }

        if( !$docId )
        {
            return;
        }

        //delete parent
        $pDataDB = $this->Database->prepare('SELECT * FROM tl_prosearch_data WHERE dca = ? AND docId = ?')->execute($tablename, $docId);

        while($pDataDB->next())
        {
            $this->deletedIndexData[] = $pDataDB->row();
        }


        //delete all childs
        $this->deleteChildrenFromIndex($tablename, $docId);
        $this->clearIndex();

    }

    /**
     * @param $ptable
     * @param $pid
     */
    public function deleteChildrenFromIndex($ptable, $pid)
    {

        $cDataDB = $this->Database->prepare('SELECT * FROM tl_prosearch_data WHERE ptable = ? AND pid = ?')->execute($ptable, $pid);

        if( $cDataDB->count() > 0 )
        {
            while( $cDataDB->next() )
            {

                $table = $cDataDB->dca;
                $id = $cDataDB->docId;
                $activeModules = deserialize(Config::get('searchIndexModules'));
                $ctables = deserialize($cDataDB->ctable);


                if( in_array( $table, $activeModules ) )
                {
                    $this->deleteChildrenFromIndex($table, $id);
                }


                if( is_array($ctables) )
                {
                    foreach($ctables as $ctable)
                    {
                        if( in_array($ctable, $activeModules) )
                        {
                            $this->deleteChildrenFromIndex($ctable, $id);
                        }
                    }
                }

                $this->deletedIndexData[] = $cDataDB->row();

            }

        }
    }

    /**
     *
     */
    public function clearIndex()
    {
        foreach($this->deletedIndexData as $indexData)
        {
            $this->Database->prepare('DELETE FROM tl_prosearch_data WHERE id = ?')->execute($indexData['id']);
        }
    }

}