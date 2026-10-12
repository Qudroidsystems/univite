<?php

namespace App\Data\Polls;

/** What one person chose: for each question, the option ids picked or the words written. */
final readonly class Ballot
{
    /**
     * @param  array<int, int[]>  $choices  question id => option ids
     * @param  array<int, string>  $texts  question id => written answer
     */
    public function __construct(public array $choices = [], public array $texts = [])
    {
    }

    /** From the voting form: answers[question id] is an option id, a list of option ids, or text. */
    public static function fromArray(array $answers): self
    {
        $choices = $texts = [];
        foreach ($answers as $qid => $v) {
            if (is_array($v)) {
                $choices[(int) $qid] = array_values(array_unique(array_map('intval', array_filter($v, 'is_numeric'))));
            } elseif (is_numeric($v)) {
                $choices[(int) $qid] = [(int) $v];
            } elseif (is_string($v) && trim($v) !== '') {
                $texts[(int) $qid] = trim($v);
            }
        }

        return new self($choices, $texts);
    }
}
