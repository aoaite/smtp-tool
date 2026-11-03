<?php

namespace Aoaite\SmtpTool\Mail;

/**
 * A message whose content is sent exactly as returned by getContent(), also with DATA:
 * no dot-stuffing and no line ending changes.
 */
interface RawMessage extends Message
{
}
