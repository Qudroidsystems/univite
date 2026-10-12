<?php

namespace App\Data\Polls;

/** The result of one question or office. */
final readonly class QuestionResult
{
    /**
     * @param  OptionTally[]  $options  most votes first
     * @param  string[]  $texts  written answers (for "written answer" questions)
     */
    public function __construct(
        public int $questionId,
        public string $prompt,
        public string $type,
        public int $ballots,
        public int $abstained,
        public array $options,
        public array $texts,
        public bool $tie,
    ) {
    }
}
