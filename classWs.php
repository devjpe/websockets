<?php
 
use Ratchet\MessageComponentInterface;
use Ratchet\ConnectionInterface;
 
class SimpleChat implements MessageComponentInterface
{
    /** @var SplObjectStorage  */
    protected $clients;
    protected $devdb;

    function __construct(){
        // Iniciamos a coleção que irá armazenar os clientes conectados
        $this->clients = new \SplObjectStorage;
        echo "\033[01;32mChat DEVJP Start OK\033[00;37m\n";
        $this->devdb = new PDO('mysql:host=sv;dbname=db','user', 'pass');
        $this->devdb->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->devdb->query('TRUNCATE dev_auth.chat_online');
        $this->devdb->query("UPDATE usuariosLogin SET idws=''");
    }

    public function verificaUsuario(){
        $select = $this->devdb->query("SELECT * FROM usuariosLogin WHERE idws!='0'");
        $cesta = array();
        while($row = $select->fetch(PDO::FETCH_OBJ)) {
            $cesta[$row->idws] = $row->idws;
        }
        foreach ($this->clients as $client) {
            if (!isset($cesta[$client->resourceId])) {
                $client->close();
            }
        }
    }

    /**
     * Evento que será chamado quando um cliente se conectar ao websocket
     */
    public function onOpen(ConnectionInterface $conn){
        // Adicionando o cliente na coleção
        $this->clients->attach($conn);
        foreach ($this->clients as $client) {
            if ($client === $conn) {
                $send = '{"resid":"'.$conn->resourceId.'"}';
                $client->send($send);
            }
        }
    }
 
    /**
     * Evento que será chamado quando um cliente enviar dados ao websocket
     *
     * @param ConnectionInterface $from
     * @param string $data
     */

    public function onMessage(ConnectionInterface $from, $data){
        $userData = (object)json_decode($data);

        //Verifica se a solicitação possui token
        if (isset($userData->token)) {

            //Verifica se o cliente esta conectado <Primeira Camada>
            $dataToken = $this->devdb->prepare("SELECT * FROM usuariosLogin WHERE token=:token and idws=:idws LIMIT 1");
            $dataToken->bindValue(':token', $userData->token, PDO::PARAM_STR);
            $dataToken->bindValue(':idws', $from->resourceId, PDO::PARAM_STR);
            $dataToken->execute();
            $rowToken = (object)$dataToken->fetch();
            
            //Verifica o cliente
            if (isset($rowToken->token)) {
                $this->verificaUsuario();
                if ($rowToken->token === $userData->token && intval($rowToken->idws) === $from->resourceId) {
                    //Dados do usuario
                    $dataTag = $this->devdb->prepare("SELECT * FROM chat_tag WHERE idtag=:idtag");
                    $dataTag->bindValue(':idtag', $rowToken->tag, PDO::PARAM_STR);
                    $dataTag->execute();
                    $rowTag = (object)$dataTag->fetch();

                    //Atualiza lista de usuarios online
                    if (isset($userData->sendCon)) {

                        //Salva no banco os usuarios Online
                        $stmt = $this->devdb->prepare('
                            INSERT INTO chat_online
                            (
                            img,
                            cortag,
                            tag,
                            nick,
                            cornick,
                            rcid,
                            idp
                            ) VALUES (
                            :userimg,
                            :usercolortag,
                            :usertag,
                            :username,
                            :cornick,
                            :usernameid,
                            :idp
                            )
                        ');

                        $UsersOnline = array(
                            'username'     => $rowToken->nick,
                            'usernameid'   => $from->resourceId,
                            'userimg'      => $rowToken->icon,
                            'usercolortag' => $rowTag->cor,
                            'usertag'      => $rowTag->tag,
                            'cornick'      => '#6b6b6b',
                            'idp'          => $rowToken->idunico
                        );

                        $stmt->execute($UsersOnline);

                        //Envia os dados para o usuario
                        foreach ($this->clients as $client) {
                            if ($client != $from) {
                                $client->send(json_encode($UsersOnline));
                            }
                        }

                    }

                    //Trata Mensagens enviadas no chat
                    if (!isset($userData->msgchat)){
                    }elseif (!trim($userData->msgchat)) {
                    }elseif ($userData->msgchat[0] != '/') {

                        //Salva historico do chat no banco
                        $stmtMsgS = $this->devdb->prepare('
                            INSERT INTO chat_dev
                            (
                            img,
                            cortag,
                            tag,
                            nick,
                            cornick,
                            mensagem,
                            hora
                            ) VALUES (
                            :linkimg,
                            :colortag, 
                            :tag, 
                            :nome, 
                            :colorname, 
                            :msgchat,
                            :hora
                            )
                        ');

                        $sendMsg = array(
                            'linkimg'   => 'upload/'.$rowToken->icon,
                            'colortag'  => $rowTag->cor,
                            'tag'       => $rowTag->tag,
                            'colorname' => '#6b6b6b',
                            'nome'      => htmlspecialchars($rowToken->nick),
                            'hora'      => date("Y-m-d H:i:s"),
                            'msgchat'   => htmlspecialchars($userData->msgchat)
                        );

                        $stmtMsgS->execute($sendMsg);

                        $sendMsgW = array(
                            'linkimg'   => 'upload/'.$rowToken->icon,
                            'colortag'  => $rowTag->cor,
                            'tag'       => $rowTag->tag,
                            'colorname' => '#6b6b6b',
                            'nome'      => htmlspecialchars($rowToken->nick),
                            'hora'      => date("d/m/Y H:i:s"),
                            'msgchat'   => htmlspecialchars($userData->msgchat)
                        );
                        $sendMsg = json_encode($sendMsgW);

                        foreach ($this->clients as $client) {
                                $client->send($sendMsg);
                        }
 

                    }elseif (isset($userData->msgchat) && $userData->msgchat[0] == '/') {
                        $sendMsg = array(
                            'linkimg'   => 'upload/c3c0095f8ee11772abf41d908ca19358.png',
                            'colortag'  => 'red',
                            'tag'       => '[Robo]',
                            'colorname' => '#6b6b6b',
                            'nome'      => 'Rick',
                            'hora'      => date("d/m/Y H:i:s"),
                            'msgchat'   => 'Calma  eu ainda não funciono!'
                        );
                        $sendMsg = json_encode($sendMsg);
                        foreach ($this->clients as $client) {
                            if ($client === $from) {
                                $client->send($sendMsg);
                            }
                        }
                    }

                }else{$from->close();}
            }else{$from->close();}
        }else{$from->close();}

    }

 
    /**
     * Evento que será chamado quando o cliente desconectar do websocket
     *
     * @param ConnectionInterface $conn
     */
    public function onClose(ConnectionInterface $conn){
        // Retirando o cliente da coleção
        $this->verificaUsuario();
        $stmt = $this->devdb->prepare('DELETE FROM chat_online WHERE rcid=:rcid');
        $stmt->bindValue(':rcid', $conn->resourceId, PDO::PARAM_STR);
        $stmt->execute();

        $stmtw = $this->devdb->prepare("UPDATE usuariosLogin SET idws='' WHERE idws=:idws");
        $stmtw->bindValue(':idws', $conn->resourceId, PDO::PARAM_STR);
        $stmtw->execute();

        $this->clients->detach($conn);
        foreach ($this->clients as $client) {
            $client->send(json_encode(array('remove' => $conn->resourceId)));
        }
    }
 
    /**
     * Evento que será chamado caso ocorra algum erro no websocket
     *
     * @param ConnectionInterface $conn
     * @param Exception $e
     */
    public function onError(ConnectionInterface $conn, \Exception $e)
    {
        // Fechando conexão do cliente
        $conn->close();
 
        echo "Ocorreu um erro: {$e->getMessage()}" . PHP_EOL;
    }
}
