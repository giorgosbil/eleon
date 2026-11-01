<?php declare(strict_types=1);
require_once __DIR__.'/auth.php';
function ecw_create_invitation(int $actorId,int $orgId,string $email):string
{
    if(!ecw_local_features() || ecw_user_role($actorId,$orgId)!=='admin') throw new RuntimeException('Administrator access required.');
    $email=strtolower(trim($email));
    if(!filter_var($email,FILTER_VALIDATE_EMAIL)||strlen($email)>320) throw new InvalidArgumentException('Enter a valid email address.');
    $st=ecw_db()->prepare('SELECT id FROM `'.ecw_table('users').'` WHERE email=?');$st->execute([$email]);
    if($st->fetchColumn()) throw new InvalidArgumentException('This email already has an account. Use the existing account.');
    require_once __DIR__.'/license.php';
    if($limit=ecw_license_user_limit_problem()) throw new InvalidArgumentException($limit);
    $token=bin2hex(random_bytes(32));
    ecw_db()->prepare('INSERT INTO `'.ecw_table('user_invitations').'` (organization_id,email,token_hash,created_by_user_id,expires_at) VALUES (?,?,?,?,?)')
        ->execute([$orgId,$email,hash('sha256',$token),$actorId,gmdate('Y-m-d H:i:s',time()+3*86400)]);
    return $token;
}
function ecw_accept_invitation(string $token,string $name,string $password):int
{
    if(!ecw_local_features() || !preg_match('/^[a-f0-9]{64}$/D',$token)) throw new InvalidArgumentException('Invalid invitation.');
    $name=trim($name);
    if($name===''||mb_strlen($name)>120) throw new InvalidArgumentException('Enter a name of up to 120 characters.');
    if($problem=ecw_password_problem($password)) throw new InvalidArgumentException($problem);
    $pdo=ecw_db();$pdo->beginTransaction();
    try {
        $st=$pdo->prepare('SELECT i.* FROM `'.ecw_table('user_invitations').'` i JOIN `'.ecw_table('users').'` u ON u.id=i.created_by_user_id JOIN `'.ecw_table('organization_users').'` ou ON ou.user_id=u.id AND ou.organization_id=i.organization_id WHERE i.token_hash=? AND i.accepted_at IS NULL AND i.expires_at>? AND u.disabled_at IS NULL AND ou.role=\'admin\' FOR UPDATE');
        $st->execute([hash('sha256',$token),ecw_now()]);$invite=$st->fetch();
        if(!$invite) throw new InvalidArgumentException('This invitation is expired, already used, or no longer valid.');
        require_once __DIR__.'/license.php';
        if($limit=ecw_license_user_limit_problem()) throw new InvalidArgumentException('The workspace is full right now. Ask your admin.');
        $pdo->prepare('INSERT INTO `'.ecw_table('users').'` (name,email,password_hash) VALUES (?,?,?)')->execute([$name,$invite['email'],password_hash($password,PASSWORD_DEFAULT)]);
        $id=(int)$pdo->lastInsertId();
        $pdo->prepare('INSERT INTO `'.ecw_table('organization_users').'` (organization_id,user_id,role) VALUES (?,?,\'user\')')->execute([$invite['organization_id'],$id]);
        $pdo->prepare('UPDATE `'.ecw_table('user_invitations').'` SET accepted_at=? WHERE id=?')->execute([ecw_now(),$invite['id']]);
        $pdo->commit();return $id;
    }catch(Throwable $e){$pdo->rollBack();throw $e;}
}
