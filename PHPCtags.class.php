<?php
class PHPCtags
{
    const VERSION = '0.5.1';

    private $mFile;

    private $mFiles;

    private static $mKinds = array(
        't' => 'trait',
        'c' => 'class',
        'm' => 'method',
        'f' => 'function',
        'p' => 'property',
        'd' => 'constant',
        'v' => 'variable',
        'i' => 'interface',
        'n' => 'namespace',
    );

    private $mParser;

    private $mStructs;

    private $mOptions;
    private $mUseConfig=array();

    public function __construct($options)
    {
        $this->mParser = new PHPParser_Parser(new PHPParser_Lexer);
        $this->mStructs = array();
        $this->mOptions = $options;
    }

    public function setMFile($file)
    {
        if (empty($file)) {
            throw new PHPCtagsException('No File specified.');
        }

        if (!file_exists($file)) {
            throw new PHPCtagsException('Warning: cannot open source file "' . $file . '" : No such file');
        }

        if (!is_readable($file)) {
            throw new PHPCtagsException('Warning: cannot open source file "' . $file . '" : File is not readable');
        }

        $this->mFile = realpath($file);
    }

    public static function getMKinds()
    {
        return self::$mKinds;
    }

    public function addFile($file)
    {
        $this->mFiles[realpath($file)] = 1;
    }

    public function addFiles($files)
    {
        foreach ($files as $file) {
            $this->addFile($file);
        }
    }

    private function getNodeAccess($node)
    {
        if ($node->isPrivate()) return 'private';
        if ($node->isProtected()) return 'protected';
        return 'public';
    }

    /**
     * stringSortByLine
     *
     * Sort a string based on its line delimiter
     *
     * @author Techlive Zheng
     *
     * @access public
     * @static
     *
     * @param string  $str     string to be sorted
     * @param boolean $foldcse case-insensitive sorting
     *
     * @return string sorted string
     **/
    public static function stringSortByLine($str, $foldcase=FALSE)
    {
        $arr = explode("\n", $str);
        if (!$foldcase)
            sort($arr, SORT_STRING);
        else
            sort($arr, SORT_STRING | SORT_FLAG_CASE);
        $str = implode("\n", $arr);
        return $str;
    }

    private static function helperSortByLine($a, $b)
    {
        return $a['line'] > $b['line'] ? 1 : 0;
    }

    private function getRealClassName($className){
        if (  $className[0] != "\\"  ){
            $ret_arr=split("\\\\", $className , 2  );
            if (count($ret_arr)==2){

                $pack_name=$ret_arr[0];
                if (isset($this->mUseConfig[ $pack_name])){
                    return  $this->mUseConfig[$pack_name]."\\".$ret_arr[1] ;
                }else{
                    return $className;
                }
            }else{
                if (isset($this->mUseConfig[$className])){
                    return  $this->mUseConfig[$className];
                }else{
                    return $className;
                }
            }
    
        }else{
            return $className;
        }

    }
    private function struct($node, $reset=FALSE, $parent=array())
    {
        static $scope = array();
        static $structs = array();

        if ($reset) {
            $structs = array();
        }

        /*$node::PHPParser_Node_Stmt_Class  */
        
        $kind = $name = $line = $access = $extends = '';
        $return_type="";
        $implements = array();



        if (!empty($parent)) array_push($scope, $parent);

        if (is_array($node)) {
            foreach ($node as $subNode) {
                $this->struct($subNode);
            }
        } elseif ($node instanceof PHPParser_Node_Stmt_UseUse ) {
            $this->mUseConfig[$node->alias ]= $node->name->toString() ;

        } elseif ($node instanceof PHPParser_Node_Stmt_Use ) {
            foreach ($node as $subNode) {
                $this->struct($subNode);
            }
        } elseif ($node instanceof PHPParser_Node_Stmt_Class) {
            $kind = 'c';
            $name = $node->name;
            $extends = $node->extends;
            $implements = $node->implements;
            $line = $node->getLine();

            $filed_scope=$scope;
            array_push($filed_scope, array('class' => $name ) );
            foreach ($node as $key=> $subNode) {
                if ($key=="stmts"){
                    foreach ($subNode as $tmpNode) {
                        $comments=$tmpNode->getAttribute("comments");
                        if (is_array($comments)){
                            foreach( $comments  as $comment ){
                                if ( preg_match(
                                    "/@var[ \t]+\\$([a-zA-Z0-9_]+)[ \t]+([a-zA-Z0-9_\\\\]+)/",
                                    $comment->getText(), $matches) ){

                                    /**  @var  $proNode PHPParser_Node_Stmt_Property  */
                                    $field_name=$matches[1];
                                    $field_return_type= $this->getRealClassName( $matches[2]);
                                    $structs[] = array(
                                        'file' => $this->mFile,
                                        'kind' => "p",
                                        'name' => $field_name,
                                        'extends' => null,
                                        'implements' => null,
                                        'line' => $comment->getLine() ,
                                        'scope' => $filed_scope ,
                                        'access' => "public",
                                        'type' => $field_return_type,
                                    );

                                }
                            }
                        }
                    }
                }
                $this->struct($subNode, FALSE, array('class' => $name));
            }
        } elseif ($node instanceof PHPParser_Node_Stmt_Property) {
            $kind = 'p';
            $prop = $node->props[0];
            $name = $prop->name;
            $line = $prop->getLine();
            if ( preg_match( "/@var[ \t]+([a-zA-Z0-9_\\\\|]+)/",$node->getDocComment(), $matches) ){
                $return_type=$this->getRealClassName( $matches[1]);
            }

            $access = $this->getNodeAccess($node);
        } elseif ($node instanceof PHPParser_Node_Stmt_ClassConst) {
            $kind = 'd';
            $cons = $node->consts[0];
            $name = $cons->name;
            $line = $cons->getLine();
        } elseif ($node instanceof PHPParser_Node_Stmt_ClassMethod) {
            $kind = 'm';
            $name = $node->name;
            $line = $node->getLine();
            $access = $this->getNodeAccess($node);
            if ( preg_match( "/@return[ \t]+([a-zA-Z0-9_\\\\|]+)/",$node->getDocComment(), $matches) ){
                $return_type=$this->getRealClassName( $matches[1]);
            }
            foreach ($node as $subNode) {
                $this->struct($subNode, FALSE, array('method' => $name));
            }
        } elseif ($node instanceof PHPParser_Node_Stmt_If) {
            foreach ($node as $subNode) {
                $this->struct($subNode);
            }
        } elseif ($node instanceof PHPParser_Node_Expr_LogicalOr ) {
            foreach ($node as $subNode) {
                $this->struct($subNode);
            }

        } elseif ($node instanceof PHPParser_Node_Stmt_Const) {
            $kind = 'd';
            $cons = $node->consts[0];
            $name = $cons->name;
            $line = $node->getLine();
        } elseif ($node instanceof PHPParser_Node_Stmt_Global) {
            /*
            $kind = 'v';
            $prop = $node->vars[0];
            $name = $prop->name;
            $line = $node->getLine();
            */
        } elseif ($node instanceof PHPParser_Node_Stmt_Static) {
            //@todo
        } elseif ($node instanceof PHPParser_Node_Stmt_Declare) {
            //@todo
        } elseif ($node instanceof PHPParser_Node_Stmt_TryCatch) {
            foreach ($node as $subNode) {
                $this->struct($subNode);
            }
        } elseif ($node instanceof PHPParser_Node_Stmt_Function) {
            $kind = 'f';
            $name = $node->name;
            $line = $node->getLine();
            if ( preg_match( "/@return[ \t]+([a-zA-Z0-9_\\\\|]+)/",$node->getDocComment(), $matches) ){
                $return_type=$this->getRealClassName( $matches[1]);
            }
            foreach ($node as $subNode) {
                $this->struct($subNode, FALSE, array('function' => $name));
            }
        } elseif ($node instanceof PHPParser_Node_Stmt_Interface) {
            $kind = 'i';
            $name = $node->name;
            $line = $node->getLine();
            foreach ($node as $subNode) {
                $this->struct($subNode, FALSE, array('interface' => $name));
            }
        } elseif ($node instanceof PHPParser_Node_Stmt_Trait ) {
            $kind = 't';
            $name = $node->name;
            $line = $node->getLine();
            foreach ($node as $subNode) {
                $this->struct($subNode, FALSE, array('trait' => $name));
            }
        } elseif ($node instanceof PHPParser_Node_Stmt_Namespace) {
            $kind = 'n';
            $name = $node->name;
            $line = $node->getLine();
            foreach ($node as $subNode) {
                $this->struct($subNode, FALSE, array('namespace' => $name));
            }
            /*
        } elseif ($node instanceof PHPParser_Node_Expr_Assign) {
            if (is_string($node->var->name)) {
                $kind = 'v';
                $node = $node->var;
                $name = $node->name;
                $line = $node->getLine();
            }
        } elseif ($node instanceof PHPParser_Node_Expr_AssignRef) {
            if (is_string($node->var->name)) {
                $kind = 'v';
                $node = $node->var;
                $name = $node->name;
                $line = $node->getLine();
            }
            */
        } elseif ($node instanceof PHPParser_Node_Expr_FuncCall) {
            switch ($node->name) {
                case 'define':
                    $kind = 'd';
                    $node = $node->args[0]->value;
                    $name = $node->value;
                    $line = $node->getLine();
                    break;
            }
        } else {
            // we don't care the rest of them.
        }

        if (!empty($kind) && !empty($name) && !empty($line)) {
            $structs[] = array(
                'file' => $this->mFile,
                'kind' => $kind,
                'name' => $name,
                'extends' => $extends,
                'implements' => $implements,
                'line' => $line,
                'scope' => $scope,
                'access' => $access,
                'type' => $return_type,
            );
        }

        if (!empty($parent)) array_pop($scope);

        // if no --sort is given, sort by occurrence
        if (!isset($this->mOptions['sort']) || $this->mOptions['sort'] == 'no') {
            usort($structs, 'self::helperSortByLine');
        }

        return $structs;
    }

    private function render()
    {
        $str = "";
        foreach ($this->mStructs as $struct) {
            $file = $struct['file'];

            if (!isset($files[$file])){
                $a = file($file);
                $files[$file] = file($file);
            }
            $lines = $files[$file];

            if (empty($struct['name']) || empty($struct['line']) || empty($struct['kind']))
                return;

            $kind= $struct['kind'];
            
            $str .= '(';
            if  ($struct['name'] instanceof PHPParser_Node_Expr_Variable ){
                $str .= '"'. addslashes( $struct['name']->name) . '" ' ;
            }else{
                $str .= '"'. addslashes( $struct['name']) . '" ' ;
            }

            $str .= ' "'. addslashes($file.":".$struct['line']  )  . '" ' ;


            if ($this->mOptions['excmd'] == 'number') {
                $str .= "\t" . $struct['line'];
            } else { //excmd == 'mixed' or 'pattern', default behavior
                #$str .= "\t" . "/^" . rtrim($lines[$struct['line'] - 1], "\n") . "$/";
                if ($kind=="f" || $kind=="m"){
                    $str .= ' "'. addslashes(rtrim($lines[$struct['line'] - 1], "\n")) . '" ' ;
                }else{
                    $str .= ' nil ' ;
                }
            }

            if ($this->mOptions['format'] == 1) {
                $str .= "\n";
                continue;
            }

            //$str .= ";\"";

            #field=k, kind of tag as single letter
            if (in_array('k', $this->mOptions['fields'])) {
                //in_array('z', $this->mOptions['fields']) && $str .= "kind:";
                //$str .= "\t" . $struct['kind'];

                $str .= ' "'. addslashes( $kind ) . '" ' ;
            } else if (in_array('K', $this->mOptions['fields'])) {
            #field=K, kind of tag as fullname
                //in_array('z', $this->mOptions['fields']) && $str .= "kind:";
                //$str .= "\t" . self::$mKinds[$struct['kind']];
                $str .= ' "'. addslashes( self::$mKinds[$kind] ) . '" ' ;
            }

            #field=n
            if (in_array('n', $this->mOptions['fields'])) {
                //$str .= "\t" . "line:" . $struct['line'];
                ;//$str .= ' "'. addslashes( $struct['line'] ) . '" ' ;
            }


            #field=s
            if (in_array('s', $this->mOptions['fields']) && !empty($struct['scope'])) {
                // $scope, $type, $name are current scope variables
                $scope = array_pop($struct['scope']);
                list($type, $name) = each($scope);
                switch ($type) {
                    case 'class':
                    case 'interface':
                        // n_* stuffs are namespace related scope variables
                        // current > class > namespace
                        $n_scope = array_pop($struct['scope']);
                        if(!empty($n_scope)) {
                            list($n_type, $n_name) = each($n_scope);
                            $s_str =  $n_name . '\\' . $name;
                        } else {
                            $s_str =   $name;
                        }

                        $s_str = "(\"" .  addslashes($type) .  "\".\"".    addslashes($s_str). "\")";
                        break;
                    case 'method':
                        // c_* stuffs are class related scope variables
                        // current > method > class > namespace
                        $c_scope = array_pop($struct['scope']);
                        list($c_type, $c_name) = each($c_scope);
                        $n_scope = array_pop($struct['scope']);
                        if(!empty($n_scope)) {
                            list($n_type, $n_name) = each($n_scope);
                            $s_str =  $n_name . '\\' . $c_name . '::' . $name;
                        } else {
                            $s_str = $c_name . '::' . $name;

                        }

                        $s_str = "(\"" .  addslashes($type) .  "\".\"".    addslashes($s_str). "\")";
                        break;
                    default:
                        $s_str = "(\"" .  addslashes($type) .  "\".\"".    addslashes($name). "\")";
                        break;
                }
                $str .= $s_str ;
            }else{
                //scope
                if( $kind == "f" || $kind == "d" || $kind == "c" || $kind == "i"  ){
                    $str .= ' () ' ;
                }
            }


            #field=i
            if(in_array('i', $this->mOptions['fields'])) {
                $inherits = array();
                if(!empty($struct['extends'])) {
                    $inherits[] =  $this->getRealClassName( $struct['extends']->toString());
                }
                if(!empty($struct['implements'])) {
                    foreach($struct['implements'] as $interface) {
                        $inherits[] = $this->getRealClassName( $interface->toString());
                    }
                }
                if(!empty($inherits)){
                    //$str .= "\t" . 'inherits:' . implode(',', $inherits);
                    $str .= ' "'. addslashes( implode(',', $inherits) ) . '" ' ;
                }else{
                    //scope
                    if(  $kind == "c" || $kind == "i"  ){
                        $str .= ' nil ' ;
                    }
                }
            }else{
                //scope
                if(  $kind == "c" || $kind == "i"  ){
                    $str .= ' nil ' ;
                }
            }

            #field=a
            if (in_array('a', $this->mOptions['fields']) && !empty($struct['access'])) {
                //$str .= "\t" . "access:" . $struct['access'];
                $str .= ' "'. addslashes(  $struct['access']  ) . '" ' ;
            }else{

            }

            #type
            if (  $kind == "f" || $kind == "p"  || $kind == "m"  ) {
                //$str .= "\t" . "type:" . $struct['type'] ;
                if ( $struct['type']  ) {
                    $str .= ' "'. addslashes(  $struct['type']  ) . '" ' ;
                }else{
                    $str .= ' nil ' ;
                }
            }



            $str .= ")\n";
        }



        // remove the last line ending
    //$str = trim($str);

    /*
        // sort the result as instructed
        if (isset($this->mOptions['sort']) && ($this->mOptions['sort'] == 'yes' || $this->mOptions['sort'] == 'foldcase')) {
            $str = self::stringSortByLine($str, $this->mOptions['sort'] == 'foldcase');
        }

    */
        return $str;
    }

    public function export()
    {
        if (empty($this->mFiles)) {
            throw new PHPCtagsException('No File specified.');
        }


        foreach (array_keys($this->mFiles) as $file) {
            $this->process($file);
        }

        $ret=$this->render();
        return $ret;
    }

    private function process($file)
    {
        if (is_dir($file) && isset($this->mOptions['R'])) {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator(
                    $file,
                    FilesystemIterator::SKIP_DOTS |
                    FilesystemIterator::FOLLOW_SYMLINKS
                )
            );

            $extensions = array('.php', '.php3', '.php4', '.php5', '.phps');

            foreach ($iterator as $filename) {
                if (!in_array(substr($filename, strrpos($filename, '.')), $extensions)) {
                    continue;
                }

                if (isset($this->mOptions['exclude']) && false !== strpos($filename, $this->mOptions['exclude'])) {
                    continue;
                }

                try {
                    $this->setMFile((string) $filename);
                    $this->mStructs = array_merge(
                        $this->mStructs,
                        $this->struct($this->mParser->parse(file_get_contents($this->mFile)), TRUE)
                    );
                } catch(Exception $e) {
                    echo "PHPParser: {$e->getMessage()} - {$filename}".PHP_EOL;
                }
            }
        } else {
            try {
                $this->setMFile($file);
                $ret_tree= $this->mParser->parse(file_get_contents($this->mFile));
                $this->mStructs = array_merge(
                    $this->mStructs,
                    $this->struct($ret_tree, TRUE)
                );
            } catch(Exception $e) {
                echo "PHPParser: {$e->getMessage()} - {$file}".PHP_EOL;
            }
        }
    }
}
  

class PHPCtagsException extends Exception {
    public function __toString() {
        return "PHPCtags: {$this->message}\n";
    }
}
