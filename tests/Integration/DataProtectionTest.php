<?php

declare(strict_types=1);

namespace Tests\Integration;

use Tests\Support\DatabaseTestCase;

final class DataProtectionTest extends DatabaseTestCase
{
    protected function tearDown(): void
    {
        $database=\src\Core\Database::resolvePath();
        $prefix=pathinfo($database,PATHINFO_FILENAME).'-pre-migration-';
        foreach (glob(dirname($database).'/backups/'.$prefix.'*.sqlite.gse') ?: [] as $backup) { @unlink($backup); }
        parent::tearDown();
    }

    /** @param list<string> $arguments */
    private function securityCli(array $arguments): int
    {
        $pipes=[];
        $process=proc_open([PHP_BINARY,ROOT_PATH.'/bin/security.php',...$arguments],
            [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
        $this->assertIsResource($process); fclose($pipes[0]);
        stream_get_contents($pipes[1]); stream_get_contents($pipes[2]);
        fclose($pipes[1]); fclose($pipes[2]);
        return proc_close($process);
    }

    public function testCliDryRunRotationReconstructionAndRestartAfterRestore(): void
    {
        $dir=sys_get_temp_dir().'/gse-security-cli-'.bin2hex(random_bytes(8)); mkdir($dir,0700);
        $originalRing=(string)file_get_contents($_ENV['GSE_KEYRING_PATH']);
        try {
            $id=$this->insertUsuario('Sintetico CLI');
            $this->assertSame(0,$this->securityCli(['preflight']));
            $this->assertSame(0,$this->securityCli(['keygen',$dir.'/other-keys.json']));
            $this->assertSame(1,$this->securityCli(['keygen',$dir.'/other-keys.json']),'Never overwrite a keyring.');
            $before=(new \Usuario())->buscarPorId($id);
            $backup=(new \Sistema())->criarBackupManual(); $this->assertIsString($backup);
            $snapshot=dirname(\src\Core\Database::resolvePath()).'/backups/'.$backup;
            $publicTarget=ROOT_PATH.'/public/.gse-restore-denied-'.bin2hex(random_bytes(8)).'.sqlite';
            $this->assertSame(1,$this->securityCli(['restore',$snapshot,$publicTarget]));
            $this->assertFileDoesNotExist($publicTarget);
            $this->assertSame(0,$this->securityCli(['rotate-key','--maintenance-confirmed']));
            $this->assertSame(0,$this->securityCli(['rotate-data','--maintenance-confirmed']));
            $this->assertTrue($before===(new \Usuario())->buscarPorId($id),'Rotation preserves account data and password hash.');
            $this->assertSame(0,$this->securityCli(['restore',$snapshot,$dir.'/restored.sqlite']));
            $this->assertSame(1,$this->securityCli(['restore',$snapshot,$dir.'/restored.sqlite']));
            $restored=new \PDO('sqlite:'.$dir.'/restored.sqlite',null,null,[\PDO::ATTR_ERRMODE=>\PDO::ERRMODE_EXCEPTION,\PDO::ATTR_DEFAULT_FETCH_MODE=>\PDO::FETCH_ASSOC]);
            \src\Core\DatabaseInitializer::initialize($restored);
            $row=\DataProtection::user($restored->query('SELECT * FROM usuarios WHERE id='.$id)->fetch());
            $this->assertTrue($before===$row,'Restore preserves account data and password hash.'); $restored=null;
            $bytes=(string)file_get_contents(\src\Core\Database::resolvePath());
            $this->assertTrue(!str_contains($bytes,'Sintetico CLI') && !str_contains($bytes,'sintetico.cli@teste.local'));
        } finally {
            file_put_contents($_ENV['GSE_KEYRING_PATH'],$originalRing);
            if (isset($snapshot)) { @unlink($snapshot); }
            foreach (glob($dir.'/*') ?: [] as $file) { unlink($file); } rmdir($dir);
        }
    }

    public function testRandomNoncesContextTamperingAndMissingKeysFailClosed(): void
    {
        $cipher = \DataProtection::encrypt('Pessoa sintética', 'usuarios.nome', 7);
        $this->assertTrue($cipher !== \DataProtection::encrypt('Pessoa sintética', 'usuarios.nome', 7));
        $this->assertSame('Pessoa sintética', \DataProtection::decrypt($cipher, 'usuarios.nome', 7));
        foreach ([[$cipher,'usuarios.nome',8],[$cipher,'usuarios.email',7],[substr($cipher,0,-5).'AAAAA','usuarios.nome',7],['texto puro','usuarios.nome',7]] as [$value,$field,$id]) {
            try { \DataProtection::decrypt($value,$field,$id); $this->fail('Invalid ciphertext accepted.'); }
            catch (\RuntimeException $exception) { $this->assertSame('Não foi possível abrir os dados protegidos.', $exception->getMessage()); }
        }
        $original = $_ENV['GSE_KEYRING_PATH'];
        try {
            $_ENV['GSE_KEYRING_PATH'] = $original . '.missing';
            $this->expectException(\RuntimeException::class);
            \DataProtection::encrypt('Pessoa sintética','usuarios.nome',1);
        } finally { $_ENV['GSE_KEYRING_PATH'] = $original; }
    }

    public function testEncryptedPersistenceSearchUniquenessAndPagination(): void
    {
        $id = $this->insertUsuario('Pessoa Sintetica');
        $raw = $this->pdo->query('SELECT id,nome,email,email_lookup FROM usuarios WHERE id=' . $id)->fetch();
        $this->assertTrue(str_starts_with($raw['nome'],'gse1:') && str_starts_with($raw['email'],'gse1:'));
        $this->assertTrue(!str_contains(json_encode($raw,JSON_THROW_ON_ERROR),'Pessoa Sintetica'));
        $model = new \Usuario();
        $this->assertSame($id,(int)$model->buscarPorEmail(' PESSOA.SINTETICA@TESTE.LOCAL ')['id']);
        $this->assertTrue($model->emailEmUso('pessoa.sintetica@teste.local'));
        $this->assertSame('Pessoa Sintetica',$model->listar('Sintetica')[0]['nome']);
        for ($i=0;$i<1005;$i++) { \test_insert_user($this->pdo,['nome'=>sprintf('Ficticio %03d',$i),'email'=>"fake$i@example.test",'senha'=>'unused','tipo'=>'funcionario']); }
        $start = microtime(true);
        $first = $model->listar('',1); $second = $model->listar('',2); $third = $model->listar('',21);
        $this->assertCount(50,$first); $this->assertCount(50,$second); $this->assertCount(6,$third);
        $this->assertSame(106,count(array_unique(array_column([...$first,...$second,...$third],'id'))));
        $this->assertLessThan(10,microtime(true)-$start,'Bounded synthetic search should finish promptly.');
        try { \test_insert_user($this->pdo,['nome'=>'Duplicado','email'=>'PESSOA.SINTETICA@TESTE.LOCAL','senha'=>'unused','tipo'=>'funcionario']); $this->fail('Duplicate accepted.'); }
        catch (\PDOException) { $this->assertSame(1006,(int)$this->pdo->query('SELECT COUNT(*) FROM usuarios')->fetchColumn()); }
        $this->pdo->exec('PRAGMA wal_checkpoint(TRUNCATE)');
        $file = \src\Core\Database::resolvePath();
        foreach ([$file,$file.'-wal'] as $path) {
            if (is_file($path)) {
                $bytes = (string)file_get_contents($path);
                $this->assertTrue(!str_contains($bytes,'Pessoa Sintetica') && !str_contains($bytes,'pessoa.sintetica@teste.local'));
            }
        }
    }

    public function testProtectedStreamRestoresAndRejectsTruncationTrailingBytesAndWrongKey(): void
    {
        $dir = sys_get_temp_dir() . '/gse-stream-' . bin2hex(random_bytes(8)); mkdir($dir,0700);
        $ringPath = $_ENV['GSE_KEYRING_PATH'];
        $original = (string)file_get_contents($ringPath);
        try {
            $content = str_repeat('Ficticio sem dados reais ',60000);
            file_put_contents($dir.'/source',$content);
            \ProtectedBackup::encrypt($dir.'/source',$dir.'/backup');
            $bytes = (string)file_get_contents($dir.'/backup');
            $this->assertTrue(!str_contains($bytes,'Ficticio sem dados reais'));
            \ProtectedBackup::decrypt($dir.'/backup',$dir.'/restored');
            $this->assertTrue(hash_equals(hash('sha256',$content),(string)hash_file('sha256',$dir.'/restored')));
            foreach ([substr($bytes,0,-1), $bytes.'x', substr_replace($bytes,'X',50,1)] as $invalid) {
                file_put_contents($dir.'/invalid',$invalid);
                try { \ProtectedBackup::decrypt($dir.'/invalid',$dir.'/rejected'); $this->fail('Invalid stream accepted.'); }
                catch (\RuntimeException) { $this->assertFileDoesNotExist($dir.'/rejected'); }
            }
            $ring = json_decode($original,true,512,JSON_THROW_ON_ERROR);
            $ring['keys']['test1'] = base64_encode(random_bytes(32));
            file_put_contents($ringPath,json_encode($ring,JSON_THROW_ON_ERROR));
            try { \ProtectedBackup::decrypt($dir.'/backup',$dir.'/wrong'); $this->fail('Wrong key accepted.'); }
            catch (\RuntimeException) { $this->assertFileDoesNotExist($dir.'/wrong'); }
            $ring = json_decode($original,true,512,JSON_THROW_ON_ERROR);
            $ring['keys']['test2'] = base64_encode(random_bytes(32)); $ring['active']='test2';
            file_put_contents($ringPath,json_encode($ring,JSON_THROW_ON_ERROR));
            \ProtectedBackup::decrypt($dir.'/backup',$dir.'/old-key');
            $new = \DataProtection::encrypt('Ficticio','usuarios.nome',1);
            $this->assertTrue(str_starts_with($new,'gse1:test2:'));
            $this->assertSame('Ficticio',\DataProtection::decrypt($new,'usuarios.nome',1));
        } finally {
            file_put_contents($ringPath,$original);
            foreach (glob($dir.'/*') ?: [] as $file) { unlink($file); } rmdir($dir);
        }
    }

    public function testVersionSixteenRequiresExplicitMigrationAndRollsBackInvalidIdentity(): void
    {
        // Build the actual prior schema by removing only version 17 from a disposable empty database.
        $this->pdo->exec('DROP TRIGGER trg_protected_identity_0; DROP TRIGGER trg_protected_identity_1;
            DROP INDEX idx_usuarios_email_lookup; ALTER TABLE usuarios DROP COLUMN email_lookup;
            DROP TABLE action_budgets; DROP TABLE request_receipts;
            DELETE FROM schema_migrations WHERE version=17; PRAGMA user_version=16');
        $this->pdo->exec("INSERT INTO usuarios(id,nome,email,senha,tipo) VALUES(41,'Sintetico Migrado','migration@example.test','legacy-preserved','funcionario')");
        try { \src\Core\DatabaseInitializer::initialize($this->pdo); $this->fail('Implicit migration accepted.'); }
        catch (\RuntimeException) { $this->assertSame(16,(int)$this->pdo->query('PRAGMA user_version')->fetchColumn()); }
        $this->pdo->exec("INSERT INTO usuarios(id,nome,email,senha,tipo) VALUES(42,'Invalido','invalid','legacy-preserved','funcionario')");
        try { \src\Core\DatabaseInitializer::initialize($this->pdo,true); $this->fail('Invalid migration accepted.'); }
        catch (\RuntimeException) {
            $this->assertSame(16,(int)$this->pdo->query('PRAGMA user_version')->fetchColumn());
            $this->assertSame('Sintetico Migrado',$this->pdo->query('SELECT nome FROM usuarios WHERE id=41')->fetchColumn());
        }
        $this->pdo->exec("UPDATE usuarios SET email='valid@example.test' WHERE id=42");
        $this->pdo->prepare('INSERT INTO login_attempts(key_type,key_hash,failure_count,first_failure_at,last_failure_at) VALUES(?,?,3,?,?)')
            ->execute(['account',hash('sha256','migration@example.test'),gmdate('Y-m-d H:i:s'),gmdate('Y-m-d H:i:s')]);
        $this->assertSame(0,$this->securityCli(['preflight']));
        $this->assertSame(16,(int)$this->pdo->query('PRAGMA user_version')->fetchColumn());
        $this->assertSame('Sintetico Migrado',$this->pdo->query('SELECT nome FROM usuarios WHERE id=41')->fetchColumn());
        $this->assertSame(0,$this->securityCli(['migrate','--maintenance-confirmed']));
        $this->assertSame(3,(new \LoginThrottle($this->pdo))->status('migration@example.test','127.0.0.1')['account_failures']);
        $row = \DataProtection::user($this->pdo->query('SELECT * FROM usuarios WHERE id=41')->fetch());
        $this->assertSame('Sintetico Migrado',$row['nome']); $this->assertSame('legacy-preserved',$row['senha']);
        $this->assertSame(21,(int)$this->pdo->query('PRAGMA user_version')->fetchColumn());
        \src\Core\DatabaseInitializer::initialize($this->pdo);
        $this->assertSame('ok',$this->pdo->query('PRAGMA integrity_check')->fetchColumn());
        $this->assertTrue(!str_contains((string)file_get_contents(\src\Core\Database::resolvePath()),'Sintetico Migrado'));
    }
}
