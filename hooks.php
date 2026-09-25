<?php

define('SS_GRAPHQL', 123 << 8);

if (file_exists(__DIR__ . '/vendor/autoload.php'))
	include_once(__DIR__ . '/vendor/autoload.php');

class hooks_graphql extends hooks
{
	var $module_name = 'graphql';

	/*
		The API is reached by URL (modules/graphql/), not through FrontAccounting's
		menu, so there is no install_options(). The security area is what a role
		must hold for its users to be let in.
	*/
	function install_access()
	{
		$security_sections[SS_GRAPHQL] = _("GraphQL API");

		$security_areas['SA_GRAPHQL'] = array(
			SS_GRAPHQL | 1, _("GraphQL API access")
		);

		return array($security_areas, $security_sections);
	}

	/*
		current_user::login() asks every active extension before it checks the
		password. The answer is true only while FaSession is logging in the user
		named by an access token it has just verified; otherwise null, which leaves
		the decision to FrontAccounting. Never false: this module has no opinion on
		anyone else's login.
	*/
	function authenticate($login, $password)
	{
		if (!class_exists('FA\GraphQL\Fa\VerifiedIdentity'))
			return null;

		return \FA\GraphQL\Fa\VerifiedIdentity::matches((int) user_company(), (string) $login) ? true : null;
	}
}
