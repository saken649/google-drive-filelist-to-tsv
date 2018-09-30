<?php
require_once __DIR__ . '/vendor/autoload.php';

$google = new Google();
$client = $google->getClient();
$ins = new MakeList($client);
$ins->main();

/**
 * Googleドライブに置いたmp4オーディオの一覧を出力する
 */
class MakeList
{
    private $service;
    private $audioList;

    const IS_DIR = 'application/vnd.google-apps.folder';
    const IS_AUDIO = 'audio/x-m4a';
    // 探索を始めたいディレクトリのID (URLから取得可能)
    const ROOT_ID = '{{GOOGLE_DRIVE_ROOT_ID}}';
    // 出力するTSVファイル名
    const TSV_FILENAME = 'list.tsv';

    public function __construct(Google_Client $client)
    {
        $this->service = new Google_Service_Drive($client);
    }

    public function main()
    {
        $this->_getList();
        $this->_exportTsv();
    }

    /**
     * GoogleDriveからフォルダー／ファイル一覧を取得する
     * listFiles()のラッパー
     * @param string $id 対象フォルダー／ファイルのid
     * @return ファイル群
     */
    private function _listFiles(string $id)
    {
        return $this->service->files->listFiles([
            'q' => sprintf("'%s' in parents", $id),
            'pageSize' => 1000,
            'orderBy' => 'name asc'
        ]);
    }

    /**
     * リストを再帰的に作成する
     * @param string $rootName ルートディレクトリのパス(=シリーズ名)
     * @param string $parentName 一階層上の親ディレクトリのパス
     * @param string $id フォルダのID
     */
    private function _getList(string $rootName = '', string $parentName = '', string $id = self::ROOT_ID): void
    {
        $list = $this->_listFiles($id);
        foreach ($list as $row) {
            if ($row['mimeType'] === self::IS_DIR) {
                // ディレクトリだった場合、さらにその下を再帰検索
                $name = $parentName . '/' . $row['name'];
                if ($id === self::ROOT_ID) $rootName = $row['name'];
                echo $name . "\n";
                $this->_getList($rootName, $name, $row['id']);
            } elseif ($row['mimeType'] === self::IS_AUDIO) {
                // オーディオファイルはリストに追加
                $this->audioList[$rootName][] = [
                    'series' => $this->_trimAudioFileName($rootName),
                    'dir' => $parentName,
                    'name' => $this->_trimAudioFileName($row['name']),
                ];
                echo "  > " . $row['name'] . "\n";
            }
        }
    }

    /**
     * トラック番号と拡張子を取り除く
     * @param $name
     * @return string
     */
    private function _trimAudioFileName($name): string
    {
        $trimed = preg_replace('/^[0-9-]*(\s|_)/', '', $name);
        return str_replace('.m4a', '', $trimed);
    }

    /**
     * TSV形式で出力する
     */
    private function _exportTsv(): void
    {
        $tsvContent = [];
        foreach ($this->audioList as $perSeries) {
            foreach ($perSeries as $file) {
                $tsvContent[] = implode("\t", $file) . "\n";
            }
        }
        if (!file_put_contents(self::TSV_FILENAME, $tsvContent)) {
            throw new Exception("作成失敗\n");
        }
        echo "書き出し終了だぷり！\n";
    }
}

/**
 * Google Driveへ接続
 * cf. https://developers.google.com/drive/api/v3/quickstart/php
 */
class Google
{
    const APP_NAME = '{{YOUR_APPLICATION_NAME}}';

    public function getClient()
    {
        $client = new Google_Client();
        $client->setApplicationName(self::APP_NAME);
        $client->setScopes(Google_Service_Drive::DRIVE_METADATA_READONLY);
        $client->setAuthConfig('credentials.json');
        $client->setAccessType('offline');
        $client->setPrompt('select_account consent');

        // 前に承認した時のトークンがあればそれで承認
        $tokenPath = 'token.json';
        if (file_exists($tokenPath)) {
            $accessToken = json_decode(file_get_contents($tokenPath), true);
            $client->setAccessToken($accessToken);
        }

        // トークンが無いor失効していたら取得or更新
        if ($client->isAccessTokenExpired()) {
            if ($client->getRefreshToken()) {
                // 失効時更新
                $client->fetchAccessTokenWithRefreshToken($client->getRefreshToken());
            } else {
                // 無い時は取得
                $authUrl = $client->createAuthUrl();
                printf("Open the following link in your browser:\n%s\n", $authUrl);
                print 'Enter verification code: ';
                $authCode = trim(fgets(STDIN));

                $accessToken = $client->fetchAccessTokenWithAuthCode($authCode);
                $client->setAccessToken($accessToken);

                // エラー時処理停止
                if (array_key_exists('error', $accessToken)) {
                    throw new Exception(join(', ', $accessToken));
                }
            }
            if (!file_exists(dirname($tokenPath))) {
                mkdir(dirname($tokenPath), 0700, true);
            }
            file_put_contents($tokenPath, json_encode($client->getAccessToken()));
        }
        return $client;
    }
}