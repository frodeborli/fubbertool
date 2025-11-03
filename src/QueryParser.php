<?php
namespace FubberTool;

use RuntimeException;

/**
 * Grammar
 * 
 * <phrase>    := string [*]
 * <phrase>    := <phrase> + <phrase>
 * <neargroup> := NEAR ( <phrase> <phrase> ... [, N] )
 * <query>     := [ [-] <colspec> :] [^] <phrase>
 * <query>     := [ [-] <colspec> :] <neargroup>
 * <query>     := [ [-] <colspec> :] ( <query> )
 * <query>     := <query> AND <query>
 * <query>     := <query> OR <query>
 * <query>     := <query> NOT <query>
 * <colspec>   := colname
 * <colspec>   := { colname1 colname2 ... }
 * 
 * @package FubberTool
 */
class QueryParser {

    private array $kinds = [];
    private array $tokens = [];
    private array $offsets = [];
    private int $offset = 0;
    private int $length = 0;

    private const ESCAPED = 'escaped';
    private const QUOTE = 'quote';
    private const OPERATOR = 'operator';
    private const LPAREN = 'lparen';
    private const RPAREN = 'rparen';
    private const AND = 'and';
    private const OR = 'or';
    private const NOT = 'not';
    private const NEAR = 'near';
    private const STR = 'str';
    private const WHITESPACE = 'whitespace';
    private const EOF = '$';

    public function parse(string $str) {
        $str = $str;
        $re = '/(?<escaped>\\\\[+\-^*()])
            |(?<quote>")
            |(?<operator>[+-^])
            |(?<lparen>\()
            |(?<rparen>\))
            |(?<and>\bAND\b)
            |(?<or>\bOR\b)
            |(?<not>\bNOT\b)
            |(?<near>\bNEAR\b)
            |(?<str>(\\\\.|[^+-^*()\s])+)
            |(?<whitespace>\s+)/msux';
        preg_match_all($re, $str, $matches, PREG_SET_ORDER|PREG_UNMATCHED_AS_NULL|PREG_OFFSET_CAPTURE, 0);
        $this->kinds = [];
        $this->tokens = [];
        $this->offsets = [];
        foreach ($matches as $n => $m) {
            $this->tokens[] = $m[0][0];
            $this->offsets[] = $m[0][1];
            foreach ($m as $kind => $v) {
                if (!is_numeric($kind) && $v === $m[0][0]) {
                    $this->kinds[] = $kind;
                    break;
                }
            }
        }
        $this->length = count($this->tokens);
        $this->offset = 0;
        $this->skipWhitespace();
        return $this->takeExpressions();
    }

    /**
     * Parses an FTS query into expressions. 
     * 
     * "one two" + three        // sequence expression with three terms
     * one + two + three        // same as above
     * "one two three"          // same as above
     * one two three            // three expressions "one", "two", "three"
     * one two *                // two expressions, the second expression is a "prefix expression"
     * ^ one two                // two expressions, the first expression is an "initial expression"
     * 
     * @return array 
     */
    private function takeExpressions(): array {
        $expressions = [];
        while (!$this->is(self::EOF)) {
            $offset = $this->offset;
            $expressions[] = $this->parseExpression();
            $this->skipWhitespace();
        }
        if ($expressions === []) {
            $this->raiseParseError("search expression");
        }
    }

    private function takeExpression(): array {
        if ($this->is(self::LPAREN)) {
            $this->take(self::LPAREN);
            $res = $this->takeExpressions();
            $this->skipWhitespace();
            $this->take(self::RPAREN);
            return [ '()', ...$res ];
        } elseif ($this->is(self::))
    }

    private function takeStr(): string {
        if ($this->is(self::EOF) || $this->is(self::WHITESPACE)) {
            $this->raiseParseError("string or phrase");
        } elseif ($this->is(self::QUOTE)) {
            // capture quoted string
            $this->take(self::QUOTE, '"');
            $res = [];
            $word = '';
            while (!$this->is(self::QUOTE) && !$this->is(self::EOF)) {
                if ($this->is(self::WHITESPACE)) {
                    if ($word !== '') {
                        $res[] = $word;
                        $word = '';
                    }
                    continue;
                } elseif ($this->is(self::ESCAPED)) {
                    // unescape
                    $word .= substr($this->tokens[$this->offset++], 1);
                } elseif (!$this->is(self::EOF)) {
                    // take anything non-whitespace and non-escaped literally when in a quoted string
                    $word .= $this->token[$this->offset++];
                }
            }
            $this->take(self::QUOTE, '"');
            if ($word !== '') {
                $res[] = $word;
            }
            return implode(" ", $res);
        } else {
            // capture single word
            $offset = $this->offset;
            $res = '';
            while (true) {
                if ($this->is(self::ESCAPED)) {
                    $res .= substr($this->tokens[$this->offset++], 1);
                } elseif ($this->is(self::STR)) {
                    $res .= $this->tokens[$this->offset++];
                } else {
                    break;
                }
            }
            if ($res === '') {
                $this->offset = $offset;
                $this->raiseParseError('string');
            }
            return $res;
        }
    }

    private function takePhrase(): array {
        $phrase = [];
        do {
            if ($this->is(self::STR)) {
                $phrase[] = $this->take(self::STR);
                $this->skipWhitespace();
            } elseif ($this->is(self::QUOTE)) {
                $this->take(self::QUOTE);
                $word = '';
                while (!$this->is(self::QUOTE)) {
                    if ($this->is(self::WHITESPACE)) {
                        if ($word !== '') {
                            $phrase[] = $word;
                            $word = '';
                        }
                        $this->skipWhitespace();
                    } else {
                        $word .= $this->tokens[$this->offset++];
                    }
                }
                if ($word !== '') {
                    $phrase[] = $word;                    
                }
                $this->take(self::QUOTE, '"');
                $this->skipWhitespace();
            }
            if ($this->is(self::OPERATOR) && $this->tokens[$this->offset] === '+') {
                $op = $this->take(self::OPERATOR);
                $this->skipWhitespace();
                // concat operator, so must capture another str
                continue;
            }
        }
    }


    private function take(string $kind, string $expects): string {
        if (!$this->is($kind)) {
            $this->raiseParseError($expects);
        }
        return $this->tokens[$this->offset++];
    }

    private function is(string $kind): bool {
        return $this->getKind() === $kind;
    }

    private function isOp(string $op): bool {
        return $this->getKind() === self::OPERATOR && $this->tokens[$this->offset] === $op;
    }

    private function getKind(): string {
        if ($this->offset === $this->length) {
            return '$';
        }
        return $this->kinds[$this->offset];
    }

    private function getToken(): string {
        if ($this->offset === $this->length) {
            return '';
        }
        return $this->tokens[$this->offset];
    }

    private function skipWhitespace(): void {
        if ($this->getKind() === 'whitespace') {
            $this->offset++;
        }
    }

    private function raiseParseError(string $expects): void {
        throw new RuntimeException("Unexpected " . $this->getKind() . ", expecting $expects at offset " . $this->offsets[$this->offset]);
    }
}
