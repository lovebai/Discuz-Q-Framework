<?php
/**
 * Copyright (C) 2021 Tencent Cloud.
 *
 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 *   http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 */

namespace Discuz\Database;

use Discuz\Common\Utils;
use Illuminate\Console\Command;
use Symfony\Component\Console\Input\InputOption;

class PluginCommand extends Command
{
    protected $name = 'migrate:plugin';
    protected $description = 'migrate plugin tables';

    public function handle()
    {
        $name = $this->input->getOption('name');
        if (empty($name)) throw new \Exception('expected one plugin name,used like [ php disco migrate:plugin --name=test ]');
        $pluginList = Utils::getPluginList();

        $basePath = base_path().'/';
        foreach ($pluginList as $item) {
            if (strtolower($item['name_en']) == strtolower($name)) {
                $paths = 'plugin_' . $item['app_id'];
                $databasePath = $item[$paths]['database'] . 'migrations';
                $databasePath = str_replace($basePath,'',$databasePath);
                if (!file_exists($databasePath)) throw new \Exception($databasePath . ' directory in ' . $item['name_en'] . '  not exist.');
                $this->call('migrate', array_filter(['--path' => $databasePath]));
                break;
            }
        }
    }

    protected function getOptions()
    {
        return [
            ['name', null, InputOption::VALUE_OPTIONAL, 'The plugin app name to use'],
            ['database', null, InputOption::VALUE_OPTIONAL, 'The database connection to use'],
            ['force', null, InputOption::VALUE_NONE, 'Force the operation to run when in production'],
            ['path', null, InputOption::VALUE_OPTIONAL | InputOption::VALUE_IS_ARRAY, 'The path(s) to the migrations files to be executed'],
            ['seeder', null, InputOption::VALUE_OPTIONAL, 'The class name of the root seeder'],
        ];
    }
}
