import {randomBytes} from 'node:crypto';
import {writeFile, chmod} from 'node:fs/promises';
import {join} from 'node:path';

export async function testKeyring(directory) {
    await chmod(directory, 0o700);
    const path = join(directory, 'test-keys.json');
    await writeFile(path, JSON.stringify({version:1, active:'test1', keys:{test1:randomBytes(32).toString('base64')},
        index_key:randomBytes(32).toString('base64')}), {mode:0o600, flag:'wx'});
    return path;
}
