<?php

namespace App\DynamoDB;

use Aws\DynamoDb\DynamoDbClient;
use DynamoDbService;
use SilverStripe\Dev\BuildTask;

class ListTablesTask extends BuildTask
{
    private static $segment = 'dynamodb-list-tables';

    protected $title = 'List DynamoDB tables';

    protected $description = 'List tables in the connected DynamoDB docker container';

    public function run($request)
    {
        $client = DynamoDbService::getClient($request);
        $response = $client->listTables();

        var_dump($response->toArray()['TableNames']);
    }
}
