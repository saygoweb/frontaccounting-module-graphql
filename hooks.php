<?php

define('SS_GRAPHQL', 123 << 8);

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
}
