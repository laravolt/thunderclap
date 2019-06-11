<?php
namespace Laravolt\Thunderclap\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Laravolt\Thunderclap\ColumnsTransformer;
use Laravolt\Thunderclap\DBHelper;
use Laravolt\Thunderclap\FileTransformer;

class Generator extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = "laravolt:clap {--table= : Code will be generated based on this table schema} {--template= : Code will be generated based on this stubs structure} {--force : Overwrite files if exists}";

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Generate basic CRUD';

    protected $DBHelper;

    protected $packerHelper;

    protected $transformer;

    /**
     * Generator constructor.
     */
    public function __construct(DBHelper $DBHelper, FileTransformer $packerHelper, ColumnsTransformer $transformer)
    {
        parent::__construct();
        $this->DBHelper = $DBHelper;
        $this->packerHelper = $packerHelper;
        $this->transformer = $transformer;
    }


    public function handle()
    {
        if (($table = $this->option('table')) === null) {
            $tables = $this->DBHelper->listTables();
            $table = $this->choice('Choose table:', $tables, null);
        }

        $columns = collect($this->DBHelper->listColumns($table));
        $this->transformer->setColumns($columns);

        $namespace = config('laravolt.thunderclap.namespace');
        $moduleName = Str::singular(str_replace('_', '', title_case($table)));
        $containerPath = config('laravolt.thunderclap.target_dir', base_path('modules'));
        $modulePath = $containerPath . DIRECTORY_SEPARATOR . $moduleName;

        // 1. check existing module
        if (is_dir($modulePath)) {
            $overwrite = $this->option('force') || $this->confirm("Folder {$modulePath} already exist, do you want to overwrite it?");
            if ($overwrite) {
                File::deleteDirectory($modulePath);
            } else {
                return false;
            }
        }

        // 2. create modules directory
        $this->info('Creating modules directory...');
        $this->packerHelper->makeDir($containerPath);
        $this->packerHelper->makeDir($modulePath);

        // 3. copy module skeleton
        $stubs = $this->getStubDir($this->option('template') ?? config('laravolt.thunderclap.default'));
        $this->info(sprintf('Generating code from %s to %s', $stubs, $modulePath));
        File::copyDirectory($stubs, $modulePath);

        $templates = [
            'module-name'  => str_replace('_', '-', Str::singular($table)),
            'route-prefix' => config('laravolt.thunderclap.routes.prefix'),
        ];

        // 4. rename file and replace common string
        $search = [
            ':Namespace:',
            ':table:',
            ':module_name:',
            ':module-name:',
            ':module name:',
            ':Module Name:',
            ':moduleName:',
            ':ModuleName:',
            ':SEARCHABLE_COLUMNS:',
            ':VALIDATION_RULES:',
            ':LANG_FIELDS:',
            ':TABLE_HEADERS:',
            ':TABLE_FIELDS:',
            ':DETAIL_FIELDS:',
            ':FORM_CREATE_FIELDS:',
            ':FORM_EDIT_FIELDS:',
            ':TABLE_VIEW_FIELDS:',
            ':VIEW_EXTENDS:',
            ':route-prefix:',
            ':route-middleware:',
            ':route-url-prefix:',
        ];
        $replace = [
            $namespace,
            $table,
            snake_case(Str::singular($table)),
            $templates['module-name'],
            str_replace('_', ' ', strtolower(Str::singular($table))),
            ucwords(str_replace('_', ' ', Str::singular($table))),
            lcfirst($moduleName),
            $moduleName,
            $this->transformer->toSearchableColumns(),
            $this->transformer->toValidationRules(),
            $this->transformer->toLangFields(),
            $this->transformer->toTableHeaders(),
            $this->transformer->toTableFields(),
            $this->transformer->toDetailFields(lcfirst($moduleName)),
            $this->transformer->toFormCreateFields(),
            $this->transformer->toFormEditFields(),
            $this->transformer->toTableViewFields(),
            config('laravolt.thunderclap.view.extends'),
            $templates['route-prefix'],
            $this->toArrayElement(config('laravolt.thunderclap.routes.middleware')),
            $this->getRouteUrlPrefix($templates['route-prefix'], $templates['module-name']),
        ];

        foreach (File::allFiles($modulePath) as $file) {
            if (is_file($file)) {

                $newFile = $deleteOriginal = false;

                if (Str::endsWith($file, '.stub')) {
                    $newFile = Str::substr($file, 0, -5);
                    $deleteOriginal = true;
                }

                if (Str::endsWith($newFile, 'Model.php')) {
                    $newFile = Str::replaceLast('Model', $moduleName, $newFile);
                }

                if (Str::endsWith($newFile, 'Controller.php')) {
                    $newFile = Str::replaceLast('Controller', $moduleName."Controller", $newFile);
                }

                if (!$newFile) {
                    continue;
                }
                $this->info($newFile);

                try {
                    $this->packerHelper->replaceAndSave($file, $search, $replace, $newFile, $deleteOriginal);
                } catch (\Exception $e) {
                    $this->error($e->getMessage());
                }

            }
        }
    }

    protected function toArrayElement($array)
    {
        $str = "";
        foreach ($array as $val) {
            $str .= "'$val'" . ",";
        }

        return substr($str, 0, -1);
    }

    protected function getRouteUrlPrefix($routePrefix, $module)
    {
        if ($routePrefix) {
            return $routePrefix . '.' . $module;
        }

        return $module;
    }

    protected function getStubDir($template)
    {
        $templateDir = config('laravolt.thunderclap.templates.'.$template);

        $dir = Str::startsWith($templateDir, DIRECTORY_SEPARATOR) ? $templateDir : __DIR__.'/../../stubs/'.$templateDir;

        if (is_dir($dir)) {
            return $dir;
        }

        throw new \InvalidArgumentException(sprintf('Invalid template directory: %s', $dir));
    }
}
