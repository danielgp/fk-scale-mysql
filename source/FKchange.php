<?php

/**
 *
 * The MIT License (MIT)
 *
 * Copyright (c) 2015 Daniel Popiniuc
 *
 * Permission is hereby granted, free of charge, to any person obtaining a copy
 * of this software and associated documentation files (the "Software"), to deal
 * in the Software without restriction, including without limitation the rights
 * to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
 * copies of the Software, and to permit persons to whom the Software is
 * furnished to do so, subject to the following conditions:
 *
 * The above copyright notice and this permission notice shall be included in all
 * copies or substantial portions of the Software.
 *
 * THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
 * IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
 * FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
 * AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
 * LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
 *  OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE
 * SOFTWARE.
 *
 */

namespace danielgp\fk_scale_mysql;

/**
 * Description of FKchange
 *
 * @author Daniel Popiniuc <danielpopiniuc@gmail.com>
 */
class FKchange
{

    use ConfigurationMySQL,
        ConfigurationForAction,
        \danielgp\common_lib\CommonCode,
        \danielgp\common_lib\MySQLiAdvancedOutput;

    private $applicationSpecificArray;

    public function __construct()
    {
        $rqst = new \Symfony\Component\HttpFoundation\Request;
        echo $this->setApplicationHeader()
        . $this->buildApplicationInterface(['SuperGlobals' => $rqst->createFromGlobals()])
        . $this->setFooterCommon();
    }

    private function buildApplicationInterface($inArray)
    {
        $mysqlConfig          = $this->configuredMySqlServer();
        $elToModify           = $this->targetElementsToModify(['SuperGlobals' => $inArray['SuperGlobals']]);
        $transmitedParameters = $this->countTransmitedParameters(['db', 'tbl', 'fld', 'dt']);
        $mConnection          = $this->connectToMySql($mysqlConfig);
        $sReturn              = [];
        $sReturn[]            = '<div class="tabber" id="tabberFKscaleMySQL">'
                . '<div class="tabbertab' . ($transmitedParameters ? '' : ' tabbertabdefault')
                . '" id="FKscaleMySQLparameters" title="Parameters for scaling">'
                . $this->buildInputFormForFKscaling($mysqlConfig, ['SuperGlobals' => $inArray['SuperGlobals']])
                . '</div><!-- end of Parameters tab -->';
        $sReturn[]            = $this->buildResultsTab($mConnection, $elToModify, $transmitedParameters);
        return implode('', $sReturn);
    }

    private function buildInputFormForFKscaling($mysqlConfig, $inArray)
    {
        $sReturn   = [];
        $sGb       = $inArray['SuperGlobals'];
        $sReturn[] = $this->buildInputs(['field' => 'db', 'label' => 'Database name to analyze'], $sGb);
        $sReturn[] = $this->buildInputs(['field' => 'tbl', 'label' => 'Table name to analyze'], $sGb);
        $sReturn[] = $this->buildInputs(['field' => 'fld', 'label' => 'Field name to analyze'], $sGb);
        $sReturn[] = $this->buildInputs(['field' => 'dt', 'label' => 'Data type to change to'], $sGb);
        $sReturn[] = '<input type="submit" value="Generate SQL queries for scaling" />';
        $sReturn[] = $this->displayMySqlConfiguration($mysqlConfig);
        return '<form method="get" action="' . filter_var($sGb->server->get('PHP_SELF'), FILTER_SANITIZE_URL) . '">'
                . implode('<br/>', $sReturn)
                . '</form>';
    }

    private function buildInputs($inArray, $sGb)
    {
        return '<label for="' . $inArray['field'] . 'Name">' . $inArray['label'] . ':</label>'
                . '<input type="text" id="' . $inArray['field'] . 'Name" name="' . $inArray['field']
                . '" placeholder="' . explode(' ', $inArray['label'])[0] . ' name" '
                . $this->returnInputsCleaned($inArray['field'], $sGb)
                . 'size="30" maxlength="64" class="labell" />';
    }

    private function buildResultsTab($mConnection, $elToModify, $transmitedParameters)
    {
        $sReturn             = [];
        $targetTableTextFlds = $this->getForeignKeys($elToModify);
        $sReturn[]           = '<div class="tabbertab' . ($transmitedParameters ? ' tabbertabdefault' : '')
                . '" id="FKscaleMySQLresults" title="Results">';
        if (is_array($targetTableTextFlds)) {
            $sReturn[]    = $this->createDropForeignKeysAndGetTargetColumnDefinition($targetTableTextFlds);
            $mainColArray = $this->packParameteresForMainChangeColumn($elToModify, $targetTableTextFlds);
            $sReturn[]    = $this->createChangeColumn($mainColArray, [
                'style'                => 'color:blue;font-weight:bold;',
                'includeOldColumnType' => true,
            ]);
            $sReturn[]    = $this->recreateFKs($elToModify, $targetTableTextFlds);
        } else {
            if (strlen($mConnection) === 0) {
                $sReturn[] = '<p style="color:red;">Check if provided parameters are correct '
                        . 'as the combination of Database. Table and Column name were not found as a Foreign Key!</p>';
            } else {
                $sReturn[] = '<p style="color:red;">Check your "configurationMySQL.php" file '
                        . 'for correct MySQL connection parameters '
                        . 'as the current ones were not able to be used to establish a connection!</p>';
            }
        }
        $sReturn[] = '</div><!-- end of FKscaleMySQLresults tab -->'
                . '</div><!-- tabberFKscaleMySQL -->';
        return implode('', $sReturn);
    }

    private function createChangeColumn($parameters, $aditionalFeatures = null)
    {
        return '<div style="'
                . (isset($aditionalFeatures['style']) ? $aditionalFeatures['style'] : 'color:blue;')
                . '">'
                . 'ALTER TABLE `' . $parameters['Database'] . '`.`' . $parameters['Table']
                . '` CHANGE `' . $parameters['Column'] . '` `' . $parameters['Column'] . '` '
                . $parameters['NewDataType'] . ' '
                . $this->setColumnDefinitionAditional($parameters['IsNullable'], $parameters['Default'])
                . (strlen($parameters['Extra']) > 0 ? ' AUTO_INCREMENT' : '')
                . (strlen($parameters['Comment']) > 0 ? ' COMMENT "' . $parameters['Comment'] . '"' : '')
                . ';'
                . (isset($aditionalFeatures['includeOldColumnType']) ? ' /* from '
                        . $parameters['OldDataType'] . ' */' : '')
                . '</div>';
    }

    private function createDropForeignKey($parameters)
    {
        return '<div style="color:red;">'
                . 'ALTER TABLE `' . $parameters['Database'] . '`.`' . $parameters['Table']
                . '` DROP FOREIGN KEY `' . $parameters['ForeignKeyName']
                . '`;'
                . '</div>';
    }

    private function createDropForeignKeysAndGetTargetColumnDefinition($targetTableTextFlds)
    {
        $sReturn = [];
        foreach ($targetTableTextFlds as $key => $value) {
            $sReturn[]                                    = $this->createDropForeignKey([
                'Database'       => $value['TABLE_SCHEMA'],
                'Table'          => $value['TABLE_NAME'],
                'ForeignKeyName' => $value['CONSTRAINT_NAME'],
            ]);
            $this->applicationSpecificArray['Cols'][$key] = $this->getMySQLlistColumns([
                'TABLE_SCHEMA' => $value['TABLE_SCHEMA'],
                'TABLE_NAME'   => $value['TABLE_NAME'],
                'COLUMN_NAME'  => $value['COLUMN_NAME'],
            ]);
        }
        return implode('', $sReturn);
    }

    private function createForeignKey($parameters)
    {
        return '<div style="color:green;">'
                . 'ALTER TABLE `' . $parameters['Database'] . '`.`' . $parameters['Table']
                . '` ADD CONSTRAINT `' . $parameters['ForeignKeyName'] . '` FOREIGN KEY (`'
                . $parameters['Column'] . '`) REFERENCES `' . $parameters['ReferencedDatabase'] . '`.`'
                . $parameters['ReferencedTable'] . '` (`' . $parameters['ReferencedColumn'] . '`) '
                . 'ON DELETE '
                . ($parameters['RuleDelete'] == 'NULL' ? 'SET NULL' : $parameters['RuleDelete']) . ' '
                . 'ON UPDATE '
                . ($parameters['RuleUpdate'] == 'NULL' ? 'SET NULL' : $parameters['RuleUpdate'])
                . ';'
                . '</div>';
    }

    private function displayMySqlConfiguration($mysqlConfig)
    {
        $styleForMySQLparams = 'color:green;font-weight:bold;font-style:italic;';
        return '<p>For security reasons the MySQL connection details are not available '
                . 'to be set/modified through the interface and must be set directly '
                . 'into the "configurationMySQL.php" file. Currently these settings are:<ul>'
                . '<li>Host name where MySQL server resides: <span style="' . $styleForMySQLparams . '">'
                . $mysqlConfig['host'] . '</span></li>'
                . '<li>MySQL port used: <span style="' . $styleForMySQLparams . '">'
                . $mysqlConfig['port'] . '</span></li>'
                . '<li>MySQL database to connect to: <span style="' . $styleForMySQLparams . '">'
                . $mysqlConfig['database'] . '</span></li>'
                . '<li>MySQL username used: <span style="' . $styleForMySQLparams . '">'
                . $mysqlConfig['username'] . '</span></li>'
                . '<li>MySQL password used: <span style="' . $styleForMySQLparams . '">'
                . 'cannot be disclosed due to security reasons</span></li>'
                . '</ul></p>';
    }

    private function getForeignKeys($elToModify)
    {
        $additionalFeatures = [
            'REFERENCED_TABLE_SCHEMA' => $elToModify['Database'],
            'REFERENCED_TABLE_NAME'   => $elToModify['Table'],
            'REFERENCED_COLUMN_NAME'  => $elToModify['Column'],
            'REFERENCED_TABLE_NAME'   => 'NOT NULL',
        ];
        $query              = $this->sQueryMySqlIndexes($additionalFeatures);
        return $this->setMySQLquery2Server($query, 'full_array_key_numbered')['result'];
    }

    private function packParameteresForMainChangeColumn($elToModify, $targetTableTextFlds)
    {
        $colToIdentify = [
            'TABLE_SCHEMA' => $elToModify['Database'],
            'TABLE_NAME'   => $elToModify['Table'],
            'COLUMN_NAME'  => $elToModify['Column'],
        ];
        $col           = $this->getMySQLlistColumns($colToIdentify);
        return [
            'Database'    => $targetTableTextFlds[0]['REFERENCED_TABLE_SCHEMA'],
            'Table'       => $targetTableTextFlds[0]['REFERENCED_TABLE_NAME'],
            'Column'      => $targetTableTextFlds[0]['REFERENCED_COLUMN_NAME'],
            'OldDataType' => strtoupper($col[0]['COLUMN_TYPE']) . ' '
            . $this->setColumnDefinitionAditional($col[0]['IS_NULLABLE'], $col[0]['COLUMN_DEFAULT'], $col[0]['EXTRA']),
            'NewDataType' => $elToModify['NewDataType'],
            'IsNullable'  => $col[0]['IS_NULLABLE'],
            'Default'     => $col[0]['COLUMN_DEFAULT'],
            'Extra'       => $col[0]['EXTRA'],
            'Comment'     => $col[0]['COLUMN_COMMENT'],
        ];
    }

    private function recreateFKs($elToModify, $targetTableTextFlds)
    {
        $sReturn = [];
        foreach ($targetTableTextFlds as $key => $value) {
            $sReturn[] = $this->createChangeColumn([
                'Database'    => $value['TABLE_SCHEMA'],
                'Table'       => $value['TABLE_NAME'],
                'Column'      => $value['COLUMN_NAME'],
                'NewDataType' => $elToModify['NewDataType'],
                'IsNullable'  => $this->applicationSpecificArray['Cols'][$key][0]['IS_NULLABLE'],
                'Default'     => $this->applicationSpecificArray['Cols'][$key][0]['COLUMN_DEFAULT'],
                'Extra'       => $this->applicationSpecificArray['Cols'][$key][0]['EXTRA'],
                'Comment'     => $this->applicationSpecificArray['Cols'][$key][0]['COLUMN_COMMENT'],
            ]);
            $sReturn[] = $this->createForeignKey([
                'Database'           => $value['TABLE_SCHEMA'],
                'Table'              => $value['TABLE_NAME'],
                'Column'             => $value['COLUMN_NAME'],
                'ForeignKeyName'     => $value['CONSTRAINT_NAME'],
                'ReferencedDatabase' => $value['REFERENCED_TABLE_SCHEMA'],
                'ReferencedTable'    => $value['REFERENCED_TABLE_NAME'],
                'ReferencedColumn'   => $value['REFERENCED_COLUMN_NAME'],
                'RuleDelete'         => $value['DELETE_RULE'],
                'RuleUpdate'         => $value['UPDATE_RULE'],
            ]);
        }
        return implode('', $sReturn);
    }

    private function returnInputsCleaned($inputFieldName, $sGb)
    {
        $sReturn = '';
        if (!is_null($sGb->get($inputFieldName))) {
            $sReturn = 'value="' . filter_var($sGb->get($inputFieldName), FILTER_SANITIZE_STRING) . '" ';
        }
        return $sReturn;
    }

    private function setApplicationHeader()
    {
        $pageTitle   = 'Foreign Keys Scale in MySQL';
        $headerArray = [
            'css'        => [
                'css/fk_scale_mysql.css',
            ],
            'javascript' => [
                'vendor/danielgp/common-lib/js/tabber/tabber-management.min.js',
                'vendor/danielgp/common-lib/js/tabber/tabber.min.js',
            ],
            'lang'       => 'en-US',
            'title'      => $pageTitle,
        ];
        return $this->setHeaderCommon($headerArray)
                . '<h1>' . $pageTitle . '</h1>';
    }

    private function setColumnDefinitionAditional($nullableYesNo, $defaultValue = '', $extra = '')
    {
        $columnDefAdtnl = $this->setColumnDefinitionAditionalPrefix($nullableYesNo, $defaultValue);
        if ($extra == 'auto_increment') {
            $columnDefAdtnl .= ' AUTO_INCREMENT';
        }
        return $columnDefAdtnl;
    }

    private function setColumnDefinitionAditionalPrefix($nullableYesNo, $defaultValue)
    {
        switch ($nullableYesNo) {
            case 'NO':
                $columnDefAdtnl = 'NOT NULL DEFAULT "' . $defaultValue . '"';
                if (is_null($defaultValue)) {
                    $columnDefAdtnl = 'NOT NULL';
                }
                break;
            case 'YES':
                $columnDefAdtnl = 'DEFAULT "' . $defaultValue . '"';
                if ($defaultValue === null) {
                    $columnDefAdtnl = 'DEFAULT NULL';
                }
                break;
        }
        return $columnDefAdtnl;
    }
}
