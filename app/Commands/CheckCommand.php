<?php

namespace App\Commands;

use App\Enums\Status;
use App\Services\Avs;
use App\Supports\CardValidate;
use LaravelZero\Framework\Commands\Command;
use PhpParser\Node\Stmt\TryCatch;
use Spatie\Fork\Fork;

use function Termwind\render;
use function Termwind\terminal;

class CheckCommand extends Command
{
    use CardValidate;

    /**
     * The signature of the command.
     *
     * @var string
     */
    protected $signature = 'check {--list= : your list txt} {--speed= : Request ratio}';

    /**
     * The description of the command.
     *
     * @var string
     */
    protected $description = 'Command description';

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
        $listFile   = $this->option('list') ?? $this->ask('Your list?', 'list.txt');
        $speed      = $this->option('speed') ?? $this->ask('Speed?', 10);
        $speed      = (int) $speed;

        if (!file_exists(getcwd() . '/' . $listFile)) {
            terminal()->clear();
            render(<<<HTML
                <div class="text-red-500 mb-1 ml-1">
                    Cannot get {$listFile}, please insert a valid path to your list.
                </div>
            HTML);
        }

        $lists = file($listFile);
        $lists = collect($lists)
            ->map(fn ($list) => str($list)->trim()->toString())
            ->toArray();

        foreach (array_chunk($lists, $speed) as $chunk) {
            $jobs = [];

            foreach ($chunk as $card) {
                if (!$this->validateRawCard($card)) {
                    continue;
                }

                $card = $this->parseCard($card);
                $jobs[] = function () use ($card) {
                    $format = collect($card->toArray())->except(['type'])->toArray();
                    $format = implode(':', $format);

                    try {
                        $avs        = new Avs($card);
                        $result     = $avs();

                        /**
                         * @var Status
                         */
                        $status = $result['result'];
                        $reason = strtoupper($result['reason'] ?? '');

                        if (!is_dir('results')) {
                            @mkdir('results');
                        }

                        file_put_contents(
                            'results/' . $status->value . '.txt',
                            $format . ':' . $reason . PHP_EOL,
                            FILE_APPEND
                        );

                        if ($status != Status::LIVE) {
                            return render(<<<HTML
                                <div class="text-red-500">{$format} {$reason}</div>
                            HTML);
                        }

                        return $this->info($format);
                    } catch (\Throwable $th) {
                        render(<<<HTML
                            <div class="text-red-500">{$format} {$th->getMessage()}</div>
                        HTML);
                    }
                };
            }

            //
            terminal()->clear();
            $this->info('Starting ' . $speed . ' request.');
            Fork::new()->run(...$jobs);

            // 3 detik
            sleep(3);
            terminal()->clear();
            $this->info('Sleep for 3 second...');
        }

        terminal()->clear();
        render(
            <<<HTML
                <div class="text-green-500">
                    Checking done.
                </div>
            HTML
        );
    }
}
