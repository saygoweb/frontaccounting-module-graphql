<?php

namespace FA\GraphQL\Tests\Unit\Fa;

use FA\GraphQL\Fa\FaText;
use PHPUnit\Framework\TestCase;

class FaTextTest extends TestCase
{
    protected function tearDown(): void
    {
        unset($_SESSION['language']);
    }

    private function language(string $encoding): void
    {
        $_SESSION['language'] = (object) ['encoding' => $encoding];
    }

    public function testDecodeUndoesHtmlSpecialsEncode(): void
    {
        $text = 'Clubs & Charities <b>"Gold"</b> \'24';
        $stored = htmlspecialchars($text, ENT_QUOTES, 'UTF-8');

        $this->assertSame($text, FaText::decode($stored));
        $this->assertSame('Hosting - Clubs & Charities', FaText::decode('Hosting - Clubs &amp; Charities'));
        $this->assertSame("it's", FaText::decode('it&#39;s'), 'the short form of a numeric entity too');
        $this->assertSame('plain', FaText::decode('plain'));
        $this->assertSame('', FaText::decode(''));
    }

    public function testEncodeIsHowDbEscapeStoresTheText(): void
    {
        $this->assertSame('Clubs &amp; Charities', FaText::encode('Clubs & Charities'));
        $this->assertSame(
            'Clubs &amp; Charities',
            FaText::encode('Clubs &amp; Charities'),
            'decoded first, as db_escape does'
        );
        $this->assertSame('&lt;b&gt;&quot;x&quot; &#039;y&#039;', FaText::encode('<b>"x" \'y\''));
        $this->assertSame('plain', FaText::encode('plain'));
    }

    public function testTheCharsetIsTheSessionLanguagesAsFrontAccountingMapsIt(): void
    {
        $this->assertSame('UTF-8', FaText::charset(), 'no session language');
        $this->language('utf-8');
        $this->assertSame('utf-8', FaText::charset());
        $this->language('iso-8859-2');
        $this->assertSame('ISO-8859-1', FaText::charset());
        $this->language('iso-8859-1');
        $this->assertSame('iso-8859-1', FaText::charset());
        $this->assertSame("\xE9 & x", FaText::decode('&eacute; &amp; x'), 'decoded into the language\'s charset');
    }

    public function testCleanInputIsHtmlCleanup(): void
    {
        $date = new \DateTimeImmutable('2026-10-21');
        $input = [
            'name' => 'R&D <Ltd> "x" \'y\'',
            'ref' => 'R&amp;D',
            'quantity' => 2.5,
            'customerId' => '5',
            'inactive' => false,
            'note' => null,
            'orderDate' => $date,
            'lines' => [['description' => 'A & B', 'quantity' => 1]],
        ];

        $this->assertSame([
            'name' => 'R&amp;D &lt;Ltd&gt; &quot;x&quot; &#039;y&#039;',
            'ref' => 'R&amp;amp;D',
            'quantity' => 2.5,
            'customerId' => '5',
            'inactive' => false,
            'note' => null,
            'orderDate' => $date,
            'lines' => [['description' => 'A &amp; B', 'quantity' => 1]],
        ], FaText::cleanInput($input));
    }

    public function testEncodeSelectorEncodesValuesNotKeys(): void
    {
        $selector = json_encode([
            'description' => 'R&D',
            'comments' => ['$in' => ['A & B', 'plain']],
            '$and' => [['id' => 5], ['name' => ['$regex' => '"x"']]],
            'inactive' => false,
        ]);

        $this->assertSame(
            '{"description":"R&amp;D","comments":{"$in":["A &amp; B","plain"]},'
            . '"$and":[{"id":5},{"name":{"$regex":"&quot;x&quot;"}}],"inactive":false}',
            FaText::encodeSelector((string) $selector)
        );
        $this->assertSame('{"id":{}}', FaText::encodeSelector('{"id":{}}'), 'an empty object stays an object');
        $this->assertSame('{"id":[]}', FaText::encodeSelector('{"id":[]}'), 'an empty list stays a list');
        $this->assertSame('{"name":"Café"}', FaText::encodeSelector('{"name":"Café"}'));
    }

    public function testEncodeSelectorLeavesWhatIsNotAnObjectForTheListToRefuse(): void
    {
        $this->assertSame('["R&D"]', FaText::encodeSelector('["R&D"]'));
        $this->assertSame('not json', FaText::encodeSelector('not json'));
        $this->assertSame(
            '{"":"R&amp;D"}',
            FaText::encodeSelector('{"":"R&D"}'),
            'its field name is for the list to refuse'
        );
    }
}
