<?php

namespace BeyondCode\Oracle;

use BeyondCode\Oracle\Exceptions\PotentiallyUnsafeQuery;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use OpenAI\Client;
use OpenAI\Exceptions\ErrorException;

class Oracle
{
    protected string $connection;

    public function __construct(protected Client $client)
    {
        $this->connection = config('ask-database.connection');
    }

    public function ask(string $question): string
    {
        $query = $this->getQuery($question);
        if (! $this->isValidSql($query)) {
            return $query; // Propagate error from getQuery
        }

        // Evaluate the query to get the result for the final prompt
        $result = json_encode($this->evaluateQuery($query));

        // Build the prompt to ask for a natural language answer based on the query and result
        $prompt = $this->buildPrompt($question, $query, $result);

        // Query OpenAI for the final natural language answer.
        // Remove the stop sequence for this call to allow multi-line answers.
        // Keep a reasonable temperature for natural language.
        $fullAnswerResponse = $this->queryOpenAi($prompt, stop: null, temperature: 0.7);

        if (! $this->isValidAnswer($fullAnswerResponse)) {
            return $fullAnswerResponse; // Return error if OpenAI call failed
        }

        // Parse the response to extract only the text after "Answer: "
        $naturalAnswer = Str::of($fullAnswerResponse)
                            ->after('Answer:') // Find the marker
                            ->trim()         // Trim whitespace
                            ->trim('"')      // Trim potential quotes
                            ->__toString();

        // Return the extracted answer, or the full response if parsing failed
        return !empty($naturalAnswer) ? $naturalAnswer : $fullAnswerResponse;
    }

    public function getQuery(string $question): string
    {
        // Build the prompt specifically to ask for an SQL query
        $prompt = $this->buildPrompt($question); // Query and result are null here

        // Query OpenAI for the SQL query string.
        // Use a stop sequence to encourage getting only the query.
        // Use low temperature for deterministic output.
        $query = $this->queryOpenAi($prompt, stop: "\nSQLResult:", temperature: 0.0); // Stop before SQLResult

        if (! $this->isValidAnswer($query)) {
             return $query; // Return error message if OpenAI call failed
        }

        // More robust cleaning: Handle potential markdown code blocks and prefixes
        $query = Str::of($query)
            ->trim(); // Initial trim

        // Extract from markdown if present
        if ($query->contains('```sql')) {
            $query = $query->between('```sql', '```');
        } elseif ($query->contains('```')) {
            $query = $query->between('```', '```');
        }

        // Remove potential prefix and final cleanup
        $query = $query->trim() // Trim again after potential extraction
                       ->whenStartsWith('SQLQuery:', fn($string) => $string->after('SQLQuery:')->trim()) // Remove prefix and trim
                       ->trim('"') // Trim quotes
                       ->trim(';'); // Trim trailing semicolon

        $queryString = $query->__toString();

        try {
            $this->ensureQueryIsSafe($queryString);
        } catch (PotentiallyUnsafeQuery $e) {
            Log::warning('Potentially unsafe query generated.', ['query' => $queryString, 'exception' => $e->getMessage()]);
            return 'Generated query was deemed potentially unsafe.';
        }

        return $queryString;
    }

    /**
     * Sends a prompt to the OpenAI Chat API.
     */
    protected function queryOpenAi(string $prompt, ?string $stop = null, float $temperature = 0.0): string
    {
        $model = config('ask-database.openai.model', 'gpt-4o');
        $maxTokens = config('ask-database.openai.max_tokens', 250); // Increased slightly for answer generation

        $apiParams = [
            'model' => $model,
            'messages' => [
                // Use the standard 'user' role
                ['role' => 'user', 'content' => $prompt],
            ],
            'max_tokens' => $maxTokens,
            'temperature' => $temperature,
        ];

        // Add stop sequence only if provided and not empty
        if (!empty($stop)) {
            $apiParams['stop'] = $stop;
        }

        try {
            // Correct call for openai-php/client v0.10.3
            $result = $this->client->chat()->create($apiParams);
            // Use object access and null-safe operator/coalescing
            return $result->choices[0]?->message?->content ?? '';
        } catch (ErrorException $e) { // Catch specific OpenAI errors
            Log::error('OpenAI API ErrorException:', ['message' => $e->getMessage(), 'code' => $e->getCode()]);
            return 'Error processing request with OpenAI: ' . $e->getMessage();
        } catch (\Exception $e) { // Catch broader exceptions
            Log::error('OpenAI API query failed unexpectedly:', ['message' => $e->getMessage()]);
            return 'Error processing request.';
        }
    }

    protected function isValidAnswer(?string $answer): bool
    {
        return !is_null($answer) && !Str::startsWith($answer, ['Error processing request', 'Generated query was deemed potentially unsafe']);
    }

    protected function isValidSql(?string $query): bool
    {
        return $this->isValidAnswer($query);
    }

    protected function buildPrompt(string $question, ?string $query = null, ?string $result = null): string
    {
        $tables = $this->getTables($question);

        // Ensure view receives boolean indicating if it's the final answer prompt
        $isFinalAnswerPrompt = !is_null($query) && !is_null($result);

        $prompt = (string) view('ask-database::prompts.query', [
            'question' => $question,
            'tables' => $tables,
            'dialect' => $this->getDialect(),
            'query' => $query,
            'result' => $result,
            'isFinalAnswerPrompt' => $isFinalAnswerPrompt,
        ]);

        // Remove the debug dump
        // dump($prompt);

        return rtrim($prompt, PHP_EOL);
    }

    protected function evaluateQuery(string $query): object
    {
        if (empty(trim($query))) {
            return new \stdClass();
        }
        try {
            // Remove the debug dump
             return DB::connection($this->connection)->select($this->getRawQuery($query))[0] ?? new \stdClass();
        } catch (\Illuminate\Database\QueryException $e) {
             Log::error('SQL Query Evaluation failed:', ['query' => $query, 'message' => $e->getMessage()]);
             return new \stdClass(); // Return empty object on failure
        }
    }

    protected function getRawQuery(string $query): string
    {
        if (empty(trim($query))) {
             return '';
        }

        if (version_compare(app()->version(), '10.0', '<')) {
            /* @phpstan-ignore-next-line */
            return (string) DB::raw($query);
        }

        return DB::raw($query)->getValue(DB::connection($this->connection)->getQueryGrammar());
    }

    /**
     * @throws PotentiallyUnsafeQuery
     */
    protected function ensureQueryIsSafe(string $query): void
    {
        if (empty(trim($query))) { // Don't check empty queries
            return;
        }

        if (! config('ask-database.strict_mode')) {
            return;
        }

        $query = strtolower($query);
        // Consider adding more keywords if needed, e.g., 'commit', 'rollback', 'grant', 'revoke'
        $forbiddenWords = ['insert', 'update', 'delete', 'alter', 'drop', 'truncate', 'create', 'replace'];
        throw_if(Str::contains($query, $forbiddenWords), PotentiallyUnsafeQuery::fromQuery($query));
    }

    protected function getDialect(): string
    {
        $connection = DB::connection($this->connection);

        return match (true) {
            $connection instanceof \Illuminate\Database\MySqlConnection && $connection->isMaria() => 'MariaDB',
            $connection instanceof \Illuminate\Database\MySqlConnection => 'MySQL',
            $connection instanceof \Illuminate\Database\PostgresConnection => 'PostgreSQL',
            $connection instanceof \Illuminate\Database\SQLiteConnection => 'SQLite',
            $connection instanceof \Illuminate\Database\SqlServerConnection => 'SQL Server',
            default => $connection->getDriverName(),
        };
    }

    protected function getTables(string $question): array
    {
        return once(function () use ($question) {
            try {
                $tables = Schema::connection($this->connection)->getTableListing();
            } catch (\Exception $e) {
                Log::error('Failed to list tables for connection.', ['connection' => $this->connection, 'message' => $e->getMessage()]);
                return []; // Return empty array on failure
            }

            if (empty($tables) || count($tables) < config('ask-database.max_tables_before_performing_lookup', 20)) {
                return $tables;
            }

            // Filter tables only if there are many, to potentially save API calls/tokens
            return $this->filterMatchingTables($question, $tables);
        });
    }

    protected function filterMatchingTables(string $question, array $tables): array
    {
        $prompt = (string) view('ask-database::prompts.tables', [
            'question' => $question,
            'tables' => $tables,
        ]);
        $prompt = rtrim($prompt, PHP_EOL);

        // Use low temperature for deterministic table filtering
        $matchingTablesResult = $this->queryOpenAi($prompt, stop: "\n", temperature: 0.0);
        if (! $this->isValidAnswer($matchingTablesResult)) {
             Log::warning('Failed to filter tables using OpenAI. Returning all tables.', ['reason' => $matchingTablesResult]);
             return $tables; // Fallback to returning all tables on error
        }

        $matchingTables = Str::of($matchingTablesResult)
            ->explode(',') // Consider that the model might use different separators
            ->transform(fn (string $tableName) => strtolower(trim($tableName)));

        return collect($tables)->filter(function ($table) use ($matchingTables) {
            // Improve robustness by checking against the raw list and the filtered list
            return $matchingTables->contains(strtolower($table));
        })->values()->toArray(); // Use values() to reset keys
    }
}
