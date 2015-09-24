<?php
class MapleParser {
    /**
     * @param $definition
     * @return string
     */
    private $conv1to1;
    private $maple2maxima = array(
        # Regex patterns for function name replacement
        array('/add([ ]?\()/','/arccos([ ]?\()/','/arcsin([ ]?\()/','/arctan([ ]?\()/','/ceil([ ]?\()/','/degree([ ]?\()/',
                '/ifactor([ ]?\()/','/igcd([ ]?\()/','/ilcm([ ]?\()/','/indets([ ]?\()/','/Insert([ ]?\()/','/int([ ]?\()/','/Limit([ ]?\()/',
                '/Matrix([ ]?\()/','/modp([ ]?\()/','/nops([ ]?\()/','/Quo([ ]?\()/','/rand([ ]?\()/','/select([ ]?\()/','/seq([ ]?\()/',
                '/simplify([ ]?\()/','/subs([ ]?\()/','/trunc([ ]?\()/','/Vector([ ]?\()/'),
        array('sum$1','acos$1','asin$1','atan$1','ceiling$1','hipow$1',
                'ifactors$1','gcd$1','lcm$1','listofvars$1','sinsert$1','integrate$1','limit$1',
                'matrix$1','mod$1','length$1','quotient$1','random$1','sublist$1','makelist$1',
                'radcan$1','subst$1','truncate$1','vector$1')
    );

    private $warningMessage = FALSE;

    public function convert($definition, $conv1to1) {
        $this->conv1to1 = $conv1to1;

        # No function? Skip the rest
        if(strpos($definition, '(') === FALSE)
            return array('warning' => FALSE, 'definition' => $definition);

        # Hacks
        $definition = str_replace(array(',-', ',+'), array(', -', ', +'), $definition);
        $definition = preg_replace('/(^[a-zA-Z]+\()/', '$1 ', $definition);

        # Build tree
        $tree = $this->_buildTree($definition);

        # Fix tree structure
        if(isset($tree[0]))
            $tree = $tree[0];
        $this->_traverseTree($tree);
        $definition = $this->_treeToString($tree);

        # Get rid of an unnecessary space
        $definition = preg_replace('/(^[a-zA-Z]+\() /', '$1', $definition);

        # Get rid of trailing comma
        $definition = strrpos($definition, ',') == strlen($definition) - 1 ? substr($definition, 0, -1) : $definition;

        if($this->warningMessage) {
            $definition = preg_replace($this->maple2maxima[0], $this->maple2maxima[1], $definition);
            return array('warning'=>TRUE, 'definition'=>$definition);
        } else
            return array('warning'=>FALSE, 'definition' =>$definition);
    }

    private function _buildTree($string, &$tree = array()) {
        if(preg_match_all('/(?:([^()]++)(?<args>\((?:[^()]++|\g<args>)*+\))*+)+/U', $string, $matches)) {
            $j=0;
            for($i=0; $i<sizeof($matches[1]); $i++) {
                if(strpos($matches[1][$i], ',') !== FALSE) {
                    if(strpos($matches[1][$i], ',') !== 0) {
                        $tree[$j][] = substr($matches[1][$i], 0, strpos($matches[1][$i], ','));
                        $matches[1][$i] = substr($matches[1][$i], strpos($matches[1][$i], ','));
                    }
                    $j++;
                }
                if(!empty($matches['args'][$i]))
                    $tree[$j][$matches[1][$i]] = $this->_buildTree($matches['args'][$i]);
                else
                    $tree[$j][] = $matches[1][$i];
            }
            return $tree;
        }
        return $tree;
    }

    private function _traverseTree(&$tree) {
        if(is_array($tree)) {
            foreach($tree as $name => $node) {
                if(!is_int($name)) {
                    if(in_array(trim($name), $this->conv1to1[0])) {
                        $key = array_search(trim($name), $this->conv1to1[0]);
                        if($this->conv1to1[0][$key] != $this->conv1to1[1][$key]) {
                            $tree[$this->conv1to1[1][$key]] = $tree[$name];
                            unset($tree[$name]);
                        }
                    } else {
                        switch($name) {
                            case 'Int':case 'int':
                                $tree['integrate'] = $tree[$name];
                                unset($tree[$name]);
                                $secondArg = $this->_treeToString($tree['integrate'][1]);
                                if(strpos($secondArg, '=') !== FALSE && preg_match('/(.+)=(.+)\.\.(.+)/', $secondArg, $matches)) {
                                    $tree['integrate'][1] = $this->convert(trim($matches[1]), $this->conv1to1)['definition'];
                                    $tree['integrate'][2] = $this->convert($matches[2], $this->conv1to1)['definition'];
                                    $tree['integrate'][3] = $this->convert($matches[3], $this->conv1to1)['definition'];
                                }
                                if($name == 'Int')
                                    $this->warningMessage = TRUE;
                                break;
                            case 'seq':
                                $tree['makelist'] = $tree[$name];
                                unset($tree[$name]);
                                if(sizeof($tree['makelist']) == 1) {
                                    $parts = explode('..', $this->_treeToString($tree['makelist']), 2);
                                    $tree['makelist'][0] = 'x';
                                    $tree['makelist'][1] = 'x';
                                    $tree['makelist'][2] = ','.$this->convert(trim($parts[0]), $this->conv1to1)['definition'];
                                    $tree['makelist'][3] = ','.$this->convert(trim($parts[1]), $this->conv1to1)['definition'];
                                } elseif(sizeof($tree['makelist']) == 2) {
                                    $firstArg = $this->_treeToString($tree['makelist'][0]);
                                    $secondArg = $this->_treeToString($tree['makelist'][1]);
                                    $tree['makelist'][0] = $this->convert(trim($firstArg), $this->conv1to1)['definition'];
                                    if(preg_match('/(.+)=(.+)\.\.(.+)/', $secondArg, $matches)) {
                                        $tree['makelist'][1] = ','.$this->convert(trim($matches[1]), $this->conv1to1)['definition'];
                                        $tree['makelist'][2] = ','.$this->convert($matches[2], $this->conv1to1)['definition'];
                                        $tree['makelist'][3] = ','.$this->convert($matches[3], $this->conv1to1)['definition'];
                                    }
                                } else {
                                    $this->warningMessage = TRUE;
                                }
                                break;
                            case 'op':
                                if(!is_array(reset($node[0]))) {
                                    $tree['part'] = $tree[$name];
                                    unset($tree[$name]);
                                    if(sizeof($tree['part']) > 1) {
                                        $tree['part'][sizeof($tree['part'])] = ',' . $tree['part'][0][0];
                                        unset($tree['part'][0]);
                                        $key = key($tree['part'][1]);
                                        if(is_string($key) && $key[0] == ',') {
                                            $key = substr($key, 1);
                                            $tree['part'][1][$key] = $tree['part'][1][",$key"];
                                            unset($tree['part'][1][",$key"]);
                                        } elseif(is_int($key) && is_string($tree['part'][1][$key]) && $tree['part'][1][$key][0] == ',') {
                                            $tree['part'][1][$key] = substr($tree['part'][1][$key], 1);
                                        }
                                    }
                                } else {
                                    //todo first arg array
                                    $this->warningMessage = TRUE;
                                }
                                break;
                            case 'convert':
                                if($tree['convert'][1][0] == ',string') {
                                    $tree['string'] = $tree[$name];
                                    unset($tree[$name]);
                                    unset($tree['string'][1]);
                                } else {
                                    $this->warningMessage = true;
                                }
                                break;
                            case 'evalb':
                                $tree['ev'] = $tree[$name];
                                unset($tree[$name]);
                                $tree['ev'][][0] = ',pred';
                                break;
                            case 'evalf':
                                $tree['ev'] = $tree[$name];
                                unset($tree[$name]);
                                $tree['ev'][][0] = ',float';
                                break;
                            case 'sort':
                                $size = sizeof($tree['sort']);
                                if($size == 2) {
                                    //todo: what to do with sort([abc],x,ascending) AND what to do with sort([abc],[$a,$b,$c])
                                    switch($tree['sort'][1][0]) {
                                        case ',ascending':
                                            $tree['sort'][1][0] = ',orderlessp';
                                            break;
                                        case ',descending':
                                            $tree['sort'][1][0] = 'ordergreatp';
                                            break;
                                        default:
                                            $this->warningMessage = true;
                                    }
                                }
                                break;
                            default:
                                if(!in_array($name, $this->conv1to1))
                                    $this->warningMessage = true;
                        }
                    }
                } else {
                    $this->_traverseTree($node);
                }
            }
        }
    }

    private function _treeToString($node, &$str = '', $parentNode = array(), $parentParentNode = array(), $functionName = FALSE) {
        if($functionName === FALSE)
            $functionName = key($node);
        if(is_array($node)) {
            foreach($node as $name => $subNode) {
                if(is_array($subNode)) {
                    if(!is_int($name)) {
                        if(($name[0] == '*' || $name[0] == '/' || $name[0] == '-' || $name[0] == '+' || $name[0] == '^') && strrpos($str, ',') == strlen($str) - 1)
                            $str = substr($str, 0, -1);
                        $str .= $name . '(';
                    }
                    $this->_treeToString($subNode, $str, $node, $parentNode, $functionName);
                } else {
                    if(($subNode[0] == '*' || $subNode[0] == '/' || $subNode[0] == '-' || $subNode[0] == '+' || $subNode[0] == '^') && strrpos($str, ',') == strlen($str) - 1)
                        $str = substr($str, 0, -1);
                    $str .= $subNode . ($subNode != end($node) ? ',' : ($node == end($parentNode) && key($parentNode) == $functionName ? ')' : ''));
                }
            }
        }

        if($node == end($parentNode)) {
            if((sizeof($parentNode) != 1 && $parentNode == end($parentParentNode)) || (is_int(key($parentNode)) && is_int(key($parentParentNode)) && $parentNode == end($parentParentNode)))
                $str .= ')';
        }
        $str = str_replace(',,', ',', $str);
        return $str;
    }
}