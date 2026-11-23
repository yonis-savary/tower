<?php

namespace Tests\Unit;

use App\TaskNotes\NoteError;
use App\TaskNotes\TaskNotesParser;
use PHPUnit\Framework\TestCase;

class TaskNotesParserTest extends TestCase
{
    private function parse(string $text): array
    {
        return (new TaskNotesParser())->parse($text)->toArray();
    }

    public function testLinesWithoutIndentationAreTitlesAndIndentedLinesTheirDescription()
    {
        $notes = $this->parse("Refonte du bandeau\n\tBouton visible sur mobile\n\t- vérifier le wording\n\t\t- avec le content\nCorriger la synchro\nPréparer la démo");

        $this->assertSame([
            ['line' => 1, 'title' => 'Refonte du bandeau', 'description' => "Bouton visible sur mobile\n- vérifier le wording\n\t- avec le content"],
            ['line' => 5, 'title' => 'Corriger la synchro', 'description' => ''],
            ['line' => 6, 'title' => 'Préparer la démo', 'description' => ''],
        ], $notes['tasks']);
        $this->assertSame([], $notes['errors']);
    }

    public function testSpacesIndentLikeTabsAndOnlyTheCommonIndentationIsRemoved()
    {
        $notes = $this->parse("Collé depuis ailleurs\n    premier point\n      détail\n    second point");

        $this->assertSame("premier point\n  détail\nsecond point", $notes['tasks'][0]['description']);
    }

    public function testCommentLinesAreIgnoredOnlyWithoutIndentation()
    {
        $notes = $this->parse("// Décision : on attend le design\nTâche\n\t// gardé dans la description\n// ignoré\n\tsuite");

        $this->assertCount(1, $notes['tasks']);
        $this->assertSame("// gardé dans la description\nsuite", $notes['tasks'][0]['description']);
    }

    public function testBlankLinesAreKeptInsideADescriptionOnly()
    {
        $notes = $this->parse("\nTâche\n\n\tpremier paragraphe\n\n\tsecond paragraphe\n\nAutre tâche\n");

        $this->assertSame("premier paragraphe\n\nsecond paragraphe", $notes['tasks'][0]['description']);
        $this->assertSame('', $notes['tasks'][1]['description']);
    }

    public function testWindowsLineEndingsAreSupported()
    {
        $notes = $this->parse("Tâche\r\n\tdétail\r\nAutre");

        $this->assertSame(['Tâche', 'Autre'], array_column($notes['tasks'], 'title'));
        $this->assertSame('détail', $notes['tasks'][0]['description']);
    }

    public function testADescriptionBeforeTheFirstTitleIsReportedOnce()
    {
        $notes = $this->parse("\tsans tâche\n\tencore\nTâche");

        $this->assertSame([['line' => 1, 'error' => NoteError::DESCRIPTION_WITHOUT_TASK]], $notes['errors']);
        $this->assertSame(['Tâche'], array_column($notes['tasks'], 'title'));
    }

    public function testTooLongTitlesAndDescriptionsAreReportedOnTheTitleLine()
    {
        $notes = $this->parse("Courte\n" . str_repeat('t', 256) . "\nAvec description\n\t" . str_repeat('d', 20001));

        $this->assertSame([
            ['line' => 2, 'error' => NoteError::TITLE_TOO_LONG],
            ['line' => 3, 'error' => NoteError::DESCRIPTION_TOO_LONG],
        ], $notes['errors']);
    }

    public function testTheFiftyFirstTaskIsReported()
    {
        $notes = $this->parse(implode("\n", array_map(fn (int $index) => "Tâche $index", range(1, 51))));

        $this->assertCount(51, $notes['tasks']);
        $this->assertSame([['line' => 51, 'error' => NoteError::TOO_MANY_TASKS]], $notes['errors']);
    }

    public function testEmptyNotesHaveNoTaskAndCannotBeCreated()
    {
        $notes = (new TaskNotesParser())->parse("\n// seulement une note\n");

        $this->assertSame([], $notes->tasks);
        $this->assertFalse($notes->isCreatable());
    }
}
