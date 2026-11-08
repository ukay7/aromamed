<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use PDO;

class ConfigureLocalMysql extends Command
{
    protected $signature = 'clinic:configure-local-mysql';
    protected $description = 'Configure the isolated local MySQL instance and separate app/test databases';

    public function handle(): int
    {
        if (! app()->environment('local')) { $this->error('Local development only.'); return self::FAILURE; }
        $secretsPath = base_path('.env.mysql.local');
        $existing = is_file($secretsPath) ? parse_ini_file($secretsPath) : [];
        $root = $existing['MYSQL_ROOT_PASSWORD'] ?? '';
        $pdo = new PDO('mysql:host=127.0.0.1;port=3308;charset=utf8mb4', 'root', $root, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $password = $existing['MYSQL_APP_PASSWORD'] ?? bin2hex(random_bytes(24));
        $testPassword = $existing['MYSQL_TEST_PASSWORD'] ?? bin2hex(random_bytes(24));
        $rootPassword = $root ?: bin2hex(random_bytes(24));
        file_put_contents($secretsPath, "MYSQL_ROOT_PASSWORD=$rootPassword\nMYSQL_APP_PASSWORD=$password\nMYSQL_TEST_PASSWORD=$testPassword\n");
        foreach (['aroma_med', 'aroma_med_test'] as $database) { $pdo->exec("CREATE DATABASE IF NOT EXISTS `$database` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"); }
        foreach (['aroma_app' => [$password, 'aroma_med'], 'aroma_test' => [$testPassword, 'aroma_med_test']] as $name => [$secret, $database]) {
            $pdo->exec("CREATE USER IF NOT EXISTS '$name'@'localhost' IDENTIFIED BY ".$pdo->quote($secret));
            $pdo->exec("ALTER USER '$name'@'localhost' IDENTIFIED BY ".$pdo->quote($secret));
            $pdo->exec("GRANT ALL PRIVILEGES ON `$database`.* TO '$name'@'localhost'");
        }
        $pdo->exec("ALTER USER 'root'@'localhost' IDENTIFIED BY ".$pdo->quote($rootPassword));
        $environment = file_get_contents(base_path('.env'));
        $values = ['DB_CONNECTION'=>'mysql','DB_HOST'=>'127.0.0.1','DB_PORT'=>'3308','DB_DATABASE'=>'aroma_med','DB_USERNAME'=>'aroma_app','DB_PASSWORD'=>$password];
        foreach ($values as $key => $value) {
            $line = $key.'='.$value;
            $environment = preg_match('/^'.preg_quote($key, '/').'=/m', $environment) ? preg_replace('/^'.preg_quote($key, '/').'=.*$/m', $line, $environment) : $environment."\n".$line;
        }
        file_put_contents(base_path('.env'), $environment."\n");
        preg_match('/^APP_KEY=(.*)$/m', $environment, $keyMatch);
        file_put_contents(base_path('.env.testing'), "APP_NAME=AromaTest\nAPP_ENV=testing\nAPP_KEY=".trim($keyMatch[1] ?? '')."\nAPP_DEBUG=true\nDB_CONNECTION=mysql\nDB_HOST=127.0.0.1\nDB_PORT=3308\nDB_DATABASE=aroma_med_test\nDB_USERNAME=aroma_test\nDB_PASSWORD=$testPassword\nCACHE_STORE=array\nSESSION_DRIVER=array\nQUEUE_CONNECTION=sync\nCLINIC_OWNER_PASSWORD=Test-Only-Password-2026!\n");
        $this->info('MySQL configured: aroma_med and isolated aroma_med_test on 127.0.0.1:3308. Credentials saved locally.');
        return self::SUCCESS;
    }
}
