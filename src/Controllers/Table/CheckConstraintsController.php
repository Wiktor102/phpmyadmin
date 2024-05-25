<?php

declare(strict_types=1);

namespace PhpMyAdmin\Controllers\Table;

use PhpMyAdmin\CheckConstraint;
use PhpMyAdmin\Config;
use PhpMyAdmin\Container\ContainerBuilder;
use PhpMyAdmin\Controllers\InvocableController;
use PhpMyAdmin\Current;
use PhpMyAdmin\DatabaseInterface;
use PhpMyAdmin\DbTableExists;
use PhpMyAdmin\Html\Generator;
use PhpMyAdmin\Http\Response;
use PhpMyAdmin\Http\ServerRequest;
use PhpMyAdmin\Identifiers\DatabaseName;
use PhpMyAdmin\Identifiers\TableName;
use PhpMyAdmin\Index;
use PhpMyAdmin\Message;
use PhpMyAdmin\MessageType;
use PhpMyAdmin\ResponseRenderer;
use PhpMyAdmin\Table\CheckConstraints;
use PhpMyAdmin\Template;
use PhpMyAdmin\Url;
use PhpMyAdmin\Util;

use function __;
use function count;
use function is_array;
use function is_numeric;
use function json_decode;
use function min;

/**
 * Displays check constraints edit/creation form and handles it.
 */
final class CheckConstraintsController implements InvocableController
{
    public function __construct(
        private readonly ResponseRenderer $response,
        private readonly Template $template,
        private readonly DatabaseInterface $dbi,
        private readonly CheckConstraints $checkConstraints,
        private readonly DbTableExists $dbTableExists,
    ) {
    }

    public function __invoke(ServerRequest $request): Response
    {
        $GLOBALS['urlParams'] ??= null;
        $GLOBALS['errorUrl'] ??= null;

        if (! isset($_POST['create_edit_table'])) {
            if (! $this->response->checkParameters(['db', 'table'])) {
                return $this->response->response();
            }

            $GLOBALS['urlParams'] = ['db' => Current::$database, 'table' => Current::$table];
            $GLOBALS['errorUrl'] = Util::getScriptNameForOption(
                Config::getInstance()->settings['DefaultTabTable'],
                'table',
            );
            $GLOBALS['errorUrl'] .= Url::getCommon($GLOBALS['urlParams'], '&');

            $databaseName = DatabaseName::tryFrom($request->getParam('db'));
            if ($databaseName === null || ! $this->dbTableExists->selectDatabase($databaseName)) {
                if ($request->isAjax()) {
                    $this->response->setRequestStatus(false);
                    $this->response->addJSON('message', Message::error(__('No databases selected.')));

                    return $this->response->response();
                }

                $this->response->redirectToRoute('/', ['reload' => true, 'message' => __('No databases selected.')]);

                return $this->response->response();
            }

            $tableName = TableName::tryFrom($request->getParam('table'));
            if ($tableName === null || ! $this->dbTableExists->hasTable($databaseName, $tableName)) {
                if ($request->isAjax()) {
                    $this->response->setRequestStatus(false);
                    $this->response->addJSON('message', Message::error(__('No table selected.')));

                    return $this->response->response();
                }

                $this->response->redirectToRoute('/', ['reload' => true, 'message' => __('No table selected.')]);

                return $this->response->response();
            }
        }

        if (isset($_POST['index'])) {
            if (is_array($_POST['index'])) {
                // coming already from form
                $checkConstraint = new CheckConstraint($_POST['index']);
            } else {
                $checkConstraint = $this->dbi->getTable(Current::$database, Current::$table)->getCheckConstraint($_POST['index']);
            }
        } else {
            $checkConstraint = new CheckConstraint();
        }

        if (isset($_POST['do_save_data'])) {
            $previewSql = $request->hasBodyParam('preview_sql');
            if (isset($_POST['old_check_constraint'])) {
                $oldCheckConstraint = is_array($_POST['old_check_constraint']) ? $_POST['old_check_constraint']['CONSTRAINT_NAME'] : $_POST['old_check_constraint'];
            } else {
                $oldCheckConstraint = null;
            }

            $sqlQuery = $this->checkConstraints->getSqlQueryForCreateOrEdit(
                $oldCheckConstraint,
                $checkConstraint,
                Current::$database,
                Current::$table,
            );

            // If there is a request for SQL previewing.
            if ($previewSql) {
                $this->response->addJSON(
                    'sql_data',
                    $this->template->render('preview_sql', ['query_data' => $sqlQuery]),
                );

                return $this->response->response();
            }

            $logicError = $this->checkConstraints->getError();
            if ($logicError instanceof Message) {
                $this->response->setRequestStatus(false);
                $this->response->addJSON('message', $logicError);

                return $this->response->response();
            }

            $this->dbi->query($sqlQuery);

            if ($request->isAjax()) {
                $message = Message::success(
                    __('Table %1$s has been altered successfully.'),
                );
                $message->addParam(Current::$table);
                $this->response->addJSON(
                    'message',
                    Generator::getMessage($message, $sqlQuery, MessageType::Success),
                );

                $indexes = Index::getFromTable($this->dbi, Current::$table, Current::$database);
                $indexesDuplicates = Index::findDuplicates(Current::$table, Current::$database);

                $this->response->addJSON(
                    'index_table',
                    $this->template->render('indexes', [
                        'url_params' => ['db' => Current::$database, 'table' => Current::$table],
                        'indexes' => $indexes,
                        'indexes_duplicates' => $indexesDuplicates,
                    ]),
                );

                return $this->response->response();
            }

            /** @var StructureController $controller */
            $controller = ContainerBuilder::getContainer()->get(StructureController::class);

            return $controller($request);
        }

        $this->displayForm($checkConstraint);

        return $this->response->response();
    }

    /**
     * Display the form to edit/create a check constraints
     *
     * @param CheckConstraint $index An Index instance.
     */
    private function displayForm(CheckConstraint $checkConstraint): void
    {
        $this->dbi->selectDb(Current::$database);
        $addFields = 0;
        // if (isset($_POST['index']) && is_array($_POST['index'])) {
        //     // coming already from form
        //     if (isset($_POST['index']['columns']['names'])) {
        //         $addFields = count($_POST['index']['columns']['names'])
        //             - $checkConstraint->getColumnCount();
        //     }

        //     if (isset($_POST['add_fields'])) {
        //         $addFields += $_POST['added_fields'];
        //     }
        // }

        $addFields = 1;

        // Get fields and stores their name/type
        if (isset($_POST['create_edit_table'])) {
            $fields = json_decode($_POST['columns'], true);
            $indexParams = ['Non_unique' => $_POST['index']['Index_choice'] !== 'UNIQUE'];
            $checkConstraint->set($indexParams);
            $addFields = count($fields);
        } else {
            $fields = $this->dbi->getTable(Current::$database, Current::$table)
                ->getNameAndTypeOfTheColumns();
        }

        $formParams = ['db' => Current::$database, 'table' => Current::$table];

        if (isset($_POST['create_check_constraint'])) {
            $formParams['create_check_constraint'] = 1;
        } elseif (isset($_POST['old_index'])) {
            $formParams['old_index'] = $_POST['old_index'];
        } elseif (isset($_POST['index'])) {
            $formParams['old_index'] = $_POST['index'];
        }

        $this->response->render('table/check_constraint_form', [
            'fields' => $fields,
            'check_constraint' => $checkConstraint,
            'form_params' => $formParams,
            'add_fields' => $addFields,
            'create_edit_table' => isset($_POST['create_edit_table']),
            'default_sliders_state' => Config::getInstance()->settings['InitialSlidersState'],
            'is_from_nav' => isset($_POST['is_from_nav']),
        ]);
    }
}
