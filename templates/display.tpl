{**
 * plugins/generic/htmlSubmissionGalley/display.tpl
 *
 * Copyright (c) 2014-2020 Simon Fraser University
 * Copyright (c) 2003-2020 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * Embedded viewing of a HTML galley.
 *}
<!DOCTYPE html>
<html lang="{$currentLocale|replace:"_":"-"}" xml:lang="{$currentLocale|replace:"_":"-"}">
{capture assign="pageTitleTranslated"}{translate key="article.pageTitle" title=$submission->getLocalizedTitle()|escape}{/capture}
{include file="frontend/components/headerHead.tpl"}
<body class="pkp_page_{$requestedPage|escape} pkp_op_{$requestedOp|escape}">

	{* Header wrapper *}
	<header class="header_view">

		{capture assign="submissionUrl"}{url page="$page" op="view" path=$submission->getBestId()}{/capture}

		<a href="{$submissionUrl}" class="return">
			<span class="pkp_screen_reader">
				{translate key="submission.return"}
			</span>
		</a>

		<a href="{$submissionUrl}" class="title">
			{$submission->getLocalizedTitle()|escape}
		</a>
	</header>

	<div id="htmlContainer" class="galley_view{if !$isLatestPublication} galley_view_with_notice{/if}" style="overflow:visible;-webkit-overflow-scrolling:touch">
		{if !$isLatestPublication}
			<div class="galley_view_notice">
				<div class="galley_view_notice_message" role="alert">
					{translate key="submission.outdatedVersion" datePublished=$galleyPublication->getData('datePublished')|date_format:$dateFormatLong urlRecentVersion=$submissionUrl}
				</div>
			</div>
			{capture assign="htmlUrl"}
				{url page="$page" op="download" path=$submission->getBestId()|to_array:'version':$galleyPublication->getId():$galley->getBestGalleyId() inline=true}
			{/capture}
		{else}
			{capture assign="htmlUrl"}
				{url page="$page" op="download" path=$submission->getBestId()|to_array:$galley->getBestGalleyId() inline=true}
			{/capture}
		{/if}
		<iframe name="htmlFrame" src="{$htmlUrl}" title="{translate key="submission.representationOfTitle" representation=$galley->getLabel() title=$galleyPublication->getLocalizedFullTitle()|escape}" allowfullscreen webkitallowfullscreen></iframe>
	</div>
	{call_hook name="Templates::Common::Footer::PageFooter"}
</body>
</html>
