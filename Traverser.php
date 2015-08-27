<?php
namespace PhpParser;
use TaConverter\XmlParser\Preparer;

require 'PhpParser/lib/bootstrap.php';

/**
 * Class Traverser
 * @package PhpParser
 * Traverser that traverses over the parsed tree (see PhpParser Docs)
 * Used for conversion from MapleTA functions to SOWISO functions
 */
class Traverser extends NodeVisitorAbstract {
    private $prettyPrinter;
    private $functionCall;
    private $binaryOp;

    public function __construct() {
        $this->prettyPrinter = new PrettyPrinter\Standard();
    }

    private function checkForConst($node, $return = FALSE) {
        foreach($node as $sub) {
            if(is_object($sub)) {
                $name = get_class($sub);
                if($name == 'PhpParser\Node\Expr\ConstFetch') {
                    $return = TRUE;
                    break;
                }
                elseif($name == 'PhpParser\Node\Expr\FuncCall') {
                    $return = FALSE;
                    break;
                }
                $return = $this->checkForConst($sub, $return);
            }
        }
        return $return;
    }

    public function beforeTraverse(array $nodes) {
        $this->functionCall = FALSE;
        $this->binaryOp = FALSE;
    }

    public function enterNode(Node $node) {
        if($node instanceof Node\Expr\FuncCall) {
            $this->functionCall = TRUE;
            if($node->name == 'sum') {
                $args = explode(PHP_EOL, $this->prettyPrinter->prettyPrint($node->args));
                $arg = new Node\Scalar\String_($node->name . '((' . str_replace('**', '^', $args[3]) . '),' . $args[0] . ', ' . $args[1] . ', '. $args[2] . ')');
                return new Node\Expr\FuncCall(new Node\Name('sw_maxima_native'), array($arg));
            }
        } elseif($node instanceof Node\Expr\BinaryOp && $this->checkForConst($node)) {
            $this->binaryOp = TRUE;
            return new Node\Expr\FuncCall(new Node\Name('sw_maxima_native'), array(new Node\Scalar\String_($this->prettyPrinter->prettyPrintExpr($node))));
        }
    }

    public function leaveNode(Node $node) {
        if($node instanceof Node\Expr\FuncCall) {
            # Strips superfluous '$' before function name (which is tolerated by MapleTA)
            if($node->name instanceof Node\Expr\Variable) {
                $node->name = new Node\Name($node->name->name);
            }

            # Replace function names and rearrange arguments
            switch($node->name) {
                case 'eq':
                    $node->name = new Node\Name('sw_eq');
                    break;
                case 'ge':
                    $node->name = new Node\Name('sw_ge');
                    break;
                case 'le':
                    $node->name = new Node\Name('sw_le');
                    break;
                case 'ne':
                    $node->name = new Node\Name('sw_ne');
                    break;
                case 'not':
                    $node->name = new Node\Name('sw_not');
                    break;
                case 'gt':
                    $node->name = new Node\Name('sw_gt');
                    break;
                case 'lt':
                    $node->name = new Node\Name('sw_lt');
                    break;
                case 'aif':
                    $node = new Node\Expr\Ternary($node->args[0]->value, $node->args[1]->value, $node->args[2]->value);
                    break;
                case 'decimal':
                    $node->name = new Node\Name('round');
                    $node->args = array_reverse($node->args);
                    break;
                case 'int':
                    $node->name = new Node\Name('sw_int');
                    break;
                case 'sig':
                    $node->name = new Node\Name('sw_round_sig');
                    $node->args = array_reverse($node->args);
                    break;
                case 'lsu':
                    $node->name = new Node\Name('sw_lsu');
                    $node->args = array_reverse($node->args);
                    break;
                case 'aswitch':
                case 'indexof':
                case 'rank' :
                    if($node->name == 'aswitch')
                        $node->name = new Node\Name('sw_alist');
                    elseif($node->name == 'indexof')
                        $node->name = new Node\Name('sw_ilist');
                    else
                        $node->name = new Node\Name('sw_rank');
                    if($node->name == 'aswitch')
                        $listIndex = new Node\Scalar\LNumber($node->args[0]->value->value - 1);
                    else
                        $listIndex = $node->args[0]; //todo: check if that's correct
                    $list = array();
                    for($i=1; $i<sizeof($node->args); $i++) {
                        $list[] = $node->args[$i]->value;
                    }
                    $node->args = array(new Node\Arg(new Node\Expr\Array_($list)), $listIndex);
                    break;
                case 'rint':
                    $node->name = new Node\Name('rand');
                    if(sizeof($node->args) == 1) {
                        $max = $node->args[0]->value;
                        $node->args = array();
                        $node->args[] = new Node\Arg(new Node\Scalar\LNumber(0));
                        if($max instanceof Node\Scalar\LNumber) {
                            $max->value--;
                            $node->args[] = new Node\Arg($max);
                        } else
                            $node->args[] = new Node\Arg(new Node\Expr\BinaryOp\Minus($max, new Node\Scalar\LNumber(1)));
                    } else {
                        if(sizeof($node->args) == 3)
                            $node->name = new Node\Name('sw_rand_steps');
                        $max = $node->args[1]->value;
                        if($max instanceof Node\Scalar\LNumber) {
                            $max->value--;
                            $node->args[1] = new Node\Arg($max);
                        } else
                            $node->args[1] = new Node\Arg(new Node\Expr\BinaryOp\Minus($max, new Node\Scalar\LNumber(1)));
                    }
                    break;
                case 'rand':
                    $node->name = new Node\Name('sw_rand_float');
                    break;
                case 'range':
                    $node->name = new Node\Name('rand');
                    if(sizeof($node->args) == 1) {
                        $node->args = array(new Node\Arg(new Node\Scalar\LNumber(0)),$node->args[0]);
                    } elseif(sizeof($node->args) == 3) {
                        $node->name = new Node\Name('sw_rand_steps');
                    }
                    break;
                case 'fact':
                    $node->name = new Node\Name('sw_fact');
                    break;
                case 'gcd':
                    $node->name = new Node\Name('sw_gcd');
                    break;
                case 'numfmt':
                    $node->name = new Node\Name('numfmt');
                    break;
                case 'max':
                case 'min':
                case 'strcat':
                    if($node->name == 'strcat')
                        $node->name = new Node\Name('sw_concat');
                    else
                        $node->name = new Node\Name('sw_'.$node->name);
                    $list = array();
                    foreach($node->args as $arg) {
                        $list[] = $arg;
                    }
                    $node->args = array(new Node\Arg(new Node\Expr\Array_($list)));
                    break;
                case 'binomial':
                    $arg = new Node\Scalar\String_('binomial(' . $node->args[0]->value->value . ', ' . $node->args[1]->value->value . ')');
                    return new Node\Expr\FuncCall(new Node\Name('sw_maxima_native'), array($arg));
                    break;
                case 'frac':
                    $a = $node->args[0]->value;
                    $b = $node->args[1]->value;
                    return new Node\Expr\BinaryOp\Div($a,$b);
                case 'csc':
                case 'sec':
                case 'cot':
                    $arg = new Node\Scalar\String_($node->name . '(' . $node->args[0]->value->value . ')');
                    return new Node\Expr\FuncCall(new Node\Name('sw_maxima_native'), array($arg));
                case 'arcsin':
                case 'arccos':
                case 'arctan':
                    $node->name = new Node\Name('a' . substr($node->name, -3));
                    break;
                case 'hypsin':
                case 'hypcos':
                case 'hyptan':
                    $node->name = new Node\Name(substr($node->name, -3) . 'h');
                    break;
                case 'archypsin':
                case 'archypcos':
                case 'archyptan':
                    $node->name = new Node\Name('a' . substr($node->name, -3) . 'h');
                    break;
                case 'ln':
                case 'log':
                    if($node->name == 'log')
                        $node->args[] = new Node\Arg(new Node\Scalar\LNumber(10));
                    $node->name = new Node\Name('log');
                    break;
            }
        } elseif($node instanceof Node\Expr\BinaryOp\Pow) {
            return new Node\Expr\FuncCall(new Node\Name('pow'), array($node->left,$node->right));
        } elseif($node instanceof Node\Expr\ConstFetch) {
            if(!$this->functionCall) {
                return new Node\Expr\FuncCall(new Node\Name('sw_maxima_native'), array(new Node\Scalar\String_($node->name->toString())));
            }  else
                return new Node\Scalar\String_($node->name->toString());
        }
        return $node;
    }
}