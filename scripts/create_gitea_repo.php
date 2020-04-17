<?php

define('SETTINGS_FILE', '/etc/redmine/gitea.ini');

if (file_exists(SETTINGS_FILE)) {
    $s = parse_ini_file(SETTINGS_FILE);
    $needed = ['api_url', 'redmine_url', 'api_token'];
    $have = array_keys($s);
    $lacking = array_diff($needed, $have);
    if (count($lacking)) {
        echo SETTINGS_FILE." is lacking ini key(s): ".join(", ", $lacking)."\n";
        die();
    }
    define('API_URL', $s['api_url']);
    define('REDMINE_URL', $s['redmine_url']);
    define('API_TOKEN', $s['api_token']);
}
else
{
    echo "No setting file: ".SETTINGS_FILE."\n";
    die();
}

/**
 * Call reset api.
 *
 * @param mixed $method
 * @param mixed $url
 * @param array $data
 *
 * @return
 */
function rest($method, $url, $data=[])
{
    $curl = curl_init();
    switch ($method) {
    case 'POST': curl_setopt($curl, CURLOPT_POST, 1);
        if ($data) {
            $data = json_encode($data);
            curl_setopt($curl, CURLOPT_POSTFIELDS, $data);
        }
        break;

    case 'PUT': curl_setopt($curl, CURLOPT_CUSTOMREQUEST, 'PUT');
        $data = json_encode($data);
        if ($data) {
            curl_setopt($curl, CURLOPT_POSTFIELDS, $data);
        }
        break;

    case 'PATCH':
        curl_setopt($curl, CURLOPT_CUSTOMREQUEST, 'PATCH');
        $data = json_encode($data);
        if ($data) {
            curl_setopt($curl, CURLOPT_POSTFIELDS, $data);
        }
        break;

    case 'DELETE':
        curl_setopt($curl, CURLOPT_CUSTOMREQUEST, 'DELETE');
        break;


    default:
        if ($data) {
            $url = sprintf('%s?%s', $url, http_build_query($data));
        }
    }//end switch

    curl_setopt($curl, CURLOPT_URL, $url);
    curl_setopt(
        $curl,
        CURLOPT_HTTPHEADER,
        [
            'Authorization: token '.API_TOKEN,
            'Content-Type: application/json',
        ]
    );
    curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($curl, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);

    $result = curl_exec($curl);
    curl_close($curl);

    if (!$result) {
        return [];
    }

    return $response = json_decode($result, true);

}//end rest()

/**
 * Do the creation of gitea repo
 *
 * @param  string $project The redmine project name used in wiki and issues
 * @param  mixed  $path
 * @param  mixed  $users
 * @return
 */
function create_gitea_repo($project, $path, $in_behalf_of, $users=[])
{
    $subfolders = explode('/', $path);
    if (count($subfolders) > 2) {
        return [
            'status'  => false,
            'message' => 'Path: '.$path.' too deep.',
        ];
    }

    $subfolder_cnt = 0;
    $org = null;
    $existing_repos = [];
    foreach ($subfolders as $sub) {
        if ($subfolder_cnt < (count($subfolders) - 1)) {
            $response = rest('GET', API_URL.'/orgs/'.$sub);
            if (($response['message'] ?? '') == 'Not Found') {
                //echo 'Create new organization: '.$sub.".. ";
                $result = create_organization($sub);
                if (ctype_digit((string) ($result['id'] ?? ''))) {
                    //echo "done\n";
                    $org = $sub;
                } else {
                    if (isset($result['message']) && preg_match("/user already exists/", $result['message']))
                    {
                        $in_behalf_of = $sub;
                        $org = null;
                    }
                    else
                    {
                        //echo 'Error: ';
                        var_dump($result);
                        die();
                    }
                }
            } else {
                $existing_repos = rest('GET', API_URL.'/orgs/'.$sub.'/repos');
                //echo 'Existing organization: '.$sub."\n";
                $org = $sub;
            }
        } else {
            $has_existing_repo = false;
            if (count($subfolders) - 1 == 1)
                $existing_repos = rest('GET', API_URL.'/user/repos');
            $existing_repo = null;
            foreach($existing_repos as $repo) {
                if ($repo['name'] == $sub) {
                    $has_existing_repo = true;
                    $existing_repo = $repo;
                    break;
                }
            }
            if (!$has_existing_repo) {
                //                echo "Creating repo: ";
                //                if ($org)
                //                    echo "$org/$sub";
                //                else
                //                    echo "$sub";
                //                echo ".. ";
                $existing_repo = create_repository($sub, $in_behalf_of, $org);
                if (ctype_digit((string) ($existing_repo['id'] ?? ''))) {
                    //echo "done\n";
                } else {
                    //echo 'Error: ';
                    var_dump($existing_repo);
                    die();
                }
            }
            else
            {
                //                echo "Existing repo: ";
                //                if ($org)
                //                    echo "$org/$sub";
                //                else
                //                    echo "$sub";
                //                echo "\n";
            }

            $owner = null;
            if (isset($existing_repo['owner']['username'])) {
                $owner = $existing_repo['owner']['username'];
            }
            if ($existing_repo && $owner ) {
                //echo "Updating wiki and issues.. ";
                $response = update_wiki_and_issues($owner, $project, $sub);
                if (ctype_digit((string) ($response['id'] ?? ''))) {
                    //echo "done\n";
                } else {
                    //echo 'Error: ';
                    var_dump($response);
                    die();
                }

                //echo "Adding collaborators: ".join(', ', $users).".. ";
                add_collaborators($owner, $sub, $users);
                //echo "done\n";

                return array_merge($existing_repo, $response);
            }
        }//end if

        ++$subfolder_cnt;
    }//end foreach

}//end create_gitea_redmine_repo()

/**
 * Create an organization in gitea
 *
 * @param  mixed $sub
 * @return
 */
function create_organization($sub)
{
    $data = [
        'description'                   => '',
        'full_name'                     => '',
        'location'                      => '',
        'repo_admin_change_team_access' => true,
        'username'                      => $sub,
        'visibility'                    => 'private',
        'website'                       => '',
    ];

    return rest('POST', API_URL.'/orgs', $data);

}//end create_organization()


/**
 * Create a repository in gitea
 *
 * @param  mixed $sub
 * @param  mixed $org
 * @return
 */
function create_repository($sub, $in_behalf_of, $org=null)
{
    $data = [
        'auto_init' => false,
        'description' => '',
        'gitignores' => '',
        'issue_labels' => '',
        'license' => '',
        'name' => $sub,
        'private' => true,
        'readme' => '',
        'empty' => true,
    ];

    if ($org != null) {
        $repo = rest('POST', API_URL.'/org/'.$org.'/repos', $data);
    } else {
        $current_user = rest('GET', API_URL.'/user');
        if ($current_user['login'] != $in_behalf_of)
        {
            $repo = rest(
                'POST',
                API_URL.'/admin/users/'.$in_behalf_of.'/repos', $data
            );
        }
        else
        {
            $repo = rest('POST', API_URL.'/user/repos', $data);
        }
    }

    return $repo;

}//end create_repository()

/**
 * Update wiki and issues of a gitea repo to point to Redmine
 *
 * @param  mixed  $owner
 * @param  string $project
 * @param  mixed  $repo
 * @return
 */
function update_wiki_and_issues($owner, $project, $repo)
{
    $data = [
        'has_issues' => true,
        'external_tracker' => [
            'external_tracker_format' => REDMINE_URL.'/issues/{index}',
            'external_tracker_style' => 'numeric',
            'external_tracker_url' => REDMINE_URL.'/projects/'.$project.'/issues'
        ],
        'has_wiki' => true,
        'external_wiki' => [
            'external_wiki_url' => REDMINE_URL.'/projects/'.$project.'/wiki',
        ],
    ];

    $times = 0;
    do
    {
        $response = rest('PATCH', API_URL.'/repos/'.$owner.'/'.$repo, $data);
        $times++;
    } while (!isset($response['external_wiki']) && $times < 100);

    return $response;
}

/**
 * Add collaborators to gitea
 * Note: Don't sync or delete as we can add manually in gitea
 *
 * @param  mixed $owner
 * @param  mixed $repo
 * @param  array $users
 * @return
 */
function add_collaborators( $owner, $repo, array $users )
{
    if (count($users) == 0) {
        return null;
    }

    $data = [
        "permission" => "write"
    ];
    foreach($users as $user) {
        rest('PUT', API_URL.'/repos/'.$owner.'/'.$repo.'/collaborators/'.$user, $data);
    }
}

if (count($_SERVER['argv']) < 4) {
    echo "need: <redmine_project> <org/repo> <in behalf of user> [users1,user2,user3]\n";
    die();
}
$project = $_SERVER['argv'][1];
$repo = $_SERVER['argv'][2];
$in_behalf_of = $_SERVER['argv'][3];
$users = [];
if (isset($_SERVER['argv'][4])) {
    $users = explode(",", $_SERVER['argv'][4]);
}
$result = create_gitea_repo($project, $repo, $in_behalf_of, $users);
echo $result['ssh_url'];

